<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CopyCoverageResult;
use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\CoverageWarning;
use App\Analytics\Data\PositionCoverageOutcome;
use App\Analytics\Data\SimulatedPosition;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use LogicException;

/**
 * Copy simulator (PROJECT.md §12, docs/DECISIONS.md D-042) on top of
 * CopyCoverageCalculator, which stays the only owner of eligibility,
 * skip reasons, breakpoints and target minimums. This class only adds what
 * the simulator shows next to them: positions back in snapshot order with
 * their estimated amount, coverage relative to the positive weight, and the
 * cash / unaccounted split of §12.5. Pure PHP, exact BCMath, no floats.
 */
final class CopySimulationCalculator
{
    private const int PPB_SCALE = 1_000_000_000;

    /**
     * |1 − Σ positions − cash| up to 0.01 percentage points is treated as
     * rounding of the source percentages, not as unaccounted weight (live
     * samples: 99.999986, D-037). The exact value is always reported.
     */
    public const int UNACCOUNTED_WEIGHT_TOLERANCE_PPB = 100_000;

    public function __construct(private readonly CopyCoverageCalculator $coverageCalculator) {}

    public function simulate(
        CopyCoverageRequest $request,
        ?Percentage $cashWeight,
        Money $platformMinimumCopyAmount,
        ?Percentage $targetCoverage = null,
    ): CopySimulationResult {
        $coverage = $this->coverageCalculator->evaluate($request);
        $unaccountedWeight = $this->unaccountedWeight($coverage, $cashWeight);

        $warnings = $this->warnings($request, $coverage, $cashWeight, $unaccountedWeight, $platformMinimumCopyAmount);

        return new CopySimulationResult(
            copyAmount: $request->copyAmount,
            minimumPositionAmount: $request->minimumPositionAmount,
            platformMinimumCopyAmount: $platformMinimumCopyAmount,
            coverage: $coverage,
            positions: $this->positionsInSnapshotOrder($request, $coverage),
            coverageOfPositiveWeight: $this->coverageOfPositiveWeight($coverage),
            cashWeight: $cashWeight,
            unaccountedWeight: $unaccountedWeight,
            target: $targetCoverage === null ? null : $this->minimumAmountForTarget($request, $targetCoverage, $platformMinimumCopyAmount),
            warnings: $warnings,
            isEstimate: array_any($warnings, fn (CopySimulationWarning $warning): bool => $warning->makesEstimate()),
        );
    }

    /**
     * §12.2 / §12.3 for the request's positions and minimum position amount
     * (its copy amount is ignored). A 100% target is exactly §12.3:
     * max(platform minimum, ceil(M / smallest positive weight)).
     */
    public function minimumAmountForTarget(CopyCoverageRequest $request, Percentage $targetCoverage, Money $platformMinimumCopyAmount): CoverageTargetResult
    {
        return $this->coverageCalculator->minimumAmountForCoverage(new CoverageTargetRequest(
            positions: $request->positions,
            targetCoverage: $targetCoverage,
            minimumPositionAmount: $request->minimumPositionAmount,
            platformMinimumCopyAmount: $platformMinimumCopyAmount,
            unmodeledEntryCount: $request->unmodeledEntryCount,
        ));
    }

    /**
     * CopyCoverageCalculator walks the positions in order and appends each
     * outcome to either the eligible or the skipped list, so both lists keep
     * the snapshot order and can be merged back. Two positions with the same
     * id and weight always have the same eligibility, so a matching eligible
     * head is never the wrong entry.
     *
     * @return list<SimulatedPosition>
     */
    private function positionsInSnapshotOrder(CopyCoverageRequest $request, CopyCoverageResult $coverage): array
    {
        $eligible = $coverage->eligiblePositions;
        $skipped = $coverage->skippedPositions;
        $eligibleCursor = 0;
        $skippedCursor = 0;
        $positions = [];

        foreach ($request->positions as $index => $position) {
            $candidate = $eligible[$eligibleCursor] ?? null;

            if ($candidate !== null && $this->matches($candidate, $position->positionId, $position->weight)) {
                $outcome = $candidate;
                $eligibleCursor++;
            } else {
                $outcome = $skipped[$skippedCursor] ?? null;

                if ($outcome === null || ! $this->matches($outcome, $position->positionId, $position->weight)) {
                    throw new LogicException('Coverage outcomes are not in request order.');
                }

                $skippedCursor++;
            }

            $positions[] = new SimulatedPosition(
                index: $index,
                outcome: $outcome,
                estimatedAmount: $position->weight->isPositive()
                    ? Money::fromCents((int) bcdiv(bcmul((string) $request->copyAmount->cents(), (string) $position->weight->partsPerBillion(), 0), (string) self::PPB_SCALE, 0))
                    : null,
            );
        }

        return $positions;
    }

    private function matches(PositionCoverageOutcome $outcome, string $positionId, Percentage $weight): bool
    {
        return $outcome->positionId === $positionId && $outcome->weight->compareTo($weight) === 0;
    }

    /**
     * Eligible weight / positive weight, floored like
     * CoverageTargetResult::$achievedRatio; null without positive weight.
     */
    private function coverageOfPositiveWeight(CopyCoverageResult $coverage): ?Percentage
    {
        $positive = (string) $coverage->positiveObservedWeight->partsPerBillion();

        if (bccomp($positive, '0', 0) <= 0) {
            return null;
        }

        $numerator = bcmul((string) $coverage->coveredWeight->partsPerBillion(), (string) self::PPB_SCALE, 0);

        return Percentage::fromPartsPerBillion((int) bcdiv($numerator, $positive, 0));
    }

    /**
     * §12.5 unknown_weight = 1 − Σ positions − cash; null when cash is
     * unknown (never assumed 0, D-037). May be negative.
     */
    private function unaccountedWeight(CopyCoverageResult $coverage, ?Percentage $cashWeight): ?Percentage
    {
        if ($cashWeight === null) {
            return null;
        }

        $accounted = bcadd((string) $coverage->totalObservedWeight->partsPerBillion(), (string) $cashWeight->partsPerBillion(), 0);

        return Percentage::fromPartsPerBillion((int) bcsub((string) self::PPB_SCALE, $accounted, 0));
    }

    /**
     * @return list<CopySimulationWarning>
     */
    private function warnings(
        CopyCoverageRequest $request,
        CopyCoverageResult $coverage,
        ?Percentage $cashWeight,
        ?Percentage $unaccountedWeight,
        Money $platformMinimumCopyAmount,
    ): array {
        $present = [
            CopySimulationWarning::EmptySnapshot->value => $coverage->isEmptyPortfolio,
            CopySimulationWarning::NoPositiveWeight->value => in_array(CoverageWarning::NoPositiveWeightObserved, $coverage->warnings, true),
            CopySimulationWarning::DuplicatePositionId->value => in_array(CoverageWarning::DuplicatePositionId, $coverage->warnings, true),
            CopySimulationWarning::NegativeWeightIgnored->value => in_array(CoverageWarning::NegativeWeightIgnored, $coverage->warnings, true),
            CopySimulationWarning::UnmodeledEntriesPresent->value => in_array(CoverageWarning::UnmodeledPortfolioEntriesPresent, $coverage->warnings, true),
            CopySimulationWarning::CashWeightUnknown->value => $cashWeight === null,
            CopySimulationWarning::UnaccountedWeight->value => $unaccountedWeight !== null
                && abs($unaccountedWeight->partsPerBillion()) > self::UNACCOUNTED_WEIGHT_TOLERANCE_PPB,
            CopySimulationWarning::CopyAmountBelowPlatformMinimum->value => $request->copyAmount->compareTo($platformMinimumCopyAmount) < 0,
        ];

        return array_values(array_filter(
            CopySimulationWarning::cases(),
            fn (CopySimulationWarning $warning): bool => $present[$warning->value],
        ));
    }
}
