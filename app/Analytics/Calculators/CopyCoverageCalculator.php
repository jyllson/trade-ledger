<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CopyCoverageResult;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\CoverageWarning;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\Data\PositionCoverageOutcome;
use App\Analytics\Data\PositionSkipReason;
use App\Analytics\Exceptions\CoverageCalculationException;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * Generic, source-neutral copy-coverage math: given a copy amount (or a
 * target coverage ratio) and a snapshot of weighted positions, determines
 * which positions are large enough to be represented at all under a
 * platform's minimum-position-amount rule. Pure PHP — no Laravel container,
 * config, storage, logging, HTTP, or eToro-domain dependency. Stateless:
 * every public method result depends only on its arguments.
 *
 * All arithmetic that combines cents/parts-per-billion values across
 * positions is done with BCMath decimal strings, never plain PHP int/float
 * operators — Money and Percentage are PHP-int-backed, and a sum of many
 * values (or a cents * ppb product) can exceed PHP_INT_MAX before it is
 * safe to hand back to those constructors.
 */
final class CopyCoverageCalculator
{
    private const int PPB_SCALE = 1_000_000_000;

    public function evaluate(CopyCoverageRequest $request): CopyCoverageResult
    {
        $copyAmountCents = $request->copyAmount->cents();
        $minimumPositionCents = $request->minimumPositionAmount->cents();

        $eligiblePositions = [];
        $skippedPositions = [];

        $coveredWeightPpbDecimal = '0';
        $skippedWeightPpbDecimal = '0';
        $totalObservedWeightPpbDecimal = '0';

        foreach ($request->positions as $position) {
            $weightPpb = $position->weight->partsPerBillion();
            $totalObservedWeightPpbDecimal = bcadd($totalObservedWeightPpbDecimal, (string) $weightPpb, 0);

            if ($position->weight->isPositive()) {
                $breakpointDecimal = $this->breakpointDecimalString($weightPpb, $minimumPositionCents);
                $minimumCopyAmountForEligibility = $this->moneyFromDecimalString($breakpointDecimal);
                $eligible = $this->isEligible($copyAmountCents, $weightPpb, $minimumPositionCents);

                $outcome = new PositionCoverageOutcome(
                    positionId: $position->positionId,
                    weight: $position->weight,
                    eligible: $eligible,
                    reason: $eligible ? null : PositionSkipReason::BelowMinimum,
                    minimumCopyAmountForEligibility: $minimumCopyAmountForEligibility,
                );

                if ($eligible) {
                    $eligiblePositions[] = $outcome;
                    $coveredWeightPpbDecimal = bcadd($coveredWeightPpbDecimal, (string) $weightPpb, 0);
                } else {
                    $skippedPositions[] = $outcome;
                    $skippedWeightPpbDecimal = bcadd($skippedWeightPpbDecimal, (string) $weightPpb, 0);
                }

                continue;
            }

            $reason = $position->weight->isZero() ? PositionSkipReason::ZeroWeight : PositionSkipReason::NegativeWeight;

            $skippedPositions[] = new PositionCoverageOutcome(
                positionId: $position->positionId,
                weight: $position->weight,
                eligible: false,
                reason: $reason,
                minimumCopyAmountForEligibility: null,
            );
        }

        $positiveObservedWeightPpbDecimal = bcadd($coveredWeightPpbDecimal, $skippedWeightPpbDecimal, 0);
        $differenceFromWholePpbDecimal = bcsub($totalObservedWeightPpbDecimal, (string) self::PPB_SCALE, 0);

        $warnings = $this->detectDataQualityWarnings($request->positions, $request->unmodeledEntryCount);

        return new CopyCoverageResult(
            eligibleCount: count($eligiblePositions),
            skippedCount: count($skippedPositions),
            coveredWeight: $this->percentageFromDecimalString($coveredWeightPpbDecimal),
            skippedWeight: $this->percentageFromDecimalString($skippedWeightPpbDecimal),
            totalObservedWeight: $this->percentageFromDecimalString($totalObservedWeightPpbDecimal),
            positiveObservedWeight: $this->percentageFromDecimalString($positiveObservedWeightPpbDecimal),
            observedWeightDifferenceFromWhole: $this->percentageFromDecimalString($differenceFromWholePpbDecimal),
            isEmptyPortfolio: $request->positions === [],
            eligiblePositions: $eligiblePositions,
            skippedPositions: $skippedPositions,
            warnings: $warnings,
        );
    }

    public function minimumAmountForPosition(PositionAllocation $position, Money $minimumPositionAmount): ?Money
    {
        if (! $minimumPositionAmount->isPositive()) {
            throw new InvalidArgumentException('minimumPositionAmount must be strictly positive.');
        }

        if (! $position->weight->isPositive()) {
            return null;
        }

        $breakpointDecimal = $this->breakpointDecimalString($position->weight->partsPerBillion(), $minimumPositionAmount->cents());

        return $this->moneyFromDecimalString($breakpointDecimal);
    }

    public function minimumAmountForCoverage(CoverageTargetRequest $request): CoverageTargetResult
    {
        $warnings = $this->detectDataQualityWarnings($request->positions, $request->unmodeledEntryCount);

        $hasNegativeWeight = false;
        $positiveObservedWeightPpbDecimal = '0';

        /** @var list<array{breakpoint: numeric-string, weightPpb: numeric-string}> $positiveWeightEntries */
        $positiveWeightEntries = [];

        foreach ($request->positions as $position) {
            if ($position->weight->isNegative()) {
                $hasNegativeWeight = true;

                continue;
            }

            if (! $position->weight->isPositive()) {
                continue;
            }

            $weightPpb = $position->weight->partsPerBillion();
            $positiveObservedWeightPpbDecimal = bcadd($positiveObservedWeightPpbDecimal, (string) $weightPpb, 0);

            $breakpoint = $this->breakpointDecimalString($weightPpb, $request->minimumPositionAmount->cents());

            $positiveWeightEntries[] = ['breakpoint' => $breakpoint, 'weightPpb' => (string) $weightPpb];
        }

        $hasIncompleteSourceData = $request->unmodeledEntryCount > 0 || $hasNegativeWeight;

        if ($positiveWeightEntries === []) {
            return new CoverageTargetResult(
                mathematicalMinimumCopyAmount: null,
                effectiveMinimumCopyAmount: null,
                targetRatio: $request->targetCoverage,
                achievedRatio: null,
                coveredRawWeight: null,
                hasIncompleteSourceData: $hasIncompleteSourceData,
                warnings: $warnings,
            );
        }

        $positiveObservedWeightPpbDecimal = $this->assertWithinSignedIntRange($positiveObservedWeightPpbDecimal);

        $targetRatioPpb = $request->targetCoverage->partsPerBillion();
        $targetNumerator = bcmul((string) $targetRatioPpb, $positiveObservedWeightPpbDecimal, 0);
        $targetAbsolutePpbDecimal = $this->exactCeilDivision($targetNumerator, (string) self::PPB_SCALE);

        usort(
            $positiveWeightEntries,
            fn (array $a, array $b): int => bccomp($a['breakpoint'], $b['breakpoint'], 0),
        );

        $cumulativeWeightPpbDecimal = '0';
        $mathematicalBreakpointDecimal = null;

        foreach ($positiveWeightEntries as $entry) {
            $cumulativeWeightPpbDecimal = bcadd($cumulativeWeightPpbDecimal, $entry['weightPpb'], 0);

            if ($mathematicalBreakpointDecimal === null && bccomp($cumulativeWeightPpbDecimal, $targetAbsolutePpbDecimal, 0) >= 0) {
                $mathematicalBreakpointDecimal = $entry['breakpoint'];
            }
        }

        if ($mathematicalBreakpointDecimal === null) {
            // Mathematically unreachable: targetAbsolutePpb is
            // ceil(targetRatioPpb * positiveObservedWeightPpb / whole) with
            // targetRatioPpb <= whole (CoverageTargetRequest invariant), so
            // it can never exceed the sum of all positive weights just
            // accumulated above. Kept only so every path has a defined,
            // typed return value.
            $lastEntry = end($positiveWeightEntries);
            $mathematicalBreakpointDecimal = $lastEntry['breakpoint'];
        }

        $mathematicalMinimumCopyAmount = $this->moneyFromDecimalString($mathematicalBreakpointDecimal);

        $effectiveMinimumCopyAmount = $mathematicalMinimumCopyAmount->compareTo($request->platformMinimumCopyAmount) >= 0
            ? $mathematicalMinimumCopyAmount
            : $request->platformMinimumCopyAmount;

        $effectiveCentsDecimal = (string) $effectiveMinimumCopyAmount->cents();

        $coveredRawWeightPpbDecimal = '0';

        foreach ($positiveWeightEntries as $entry) {
            if (bccomp($entry['breakpoint'], $effectiveCentsDecimal, 0) <= 0) {
                $coveredRawWeightPpbDecimal = bcadd($coveredRawWeightPpbDecimal, $entry['weightPpb'], 0);
            }
        }

        $coveredRawWeight = $this->percentageFromDecimalString($coveredRawWeightPpbDecimal);

        $achievedNumerator = bcmul($coveredRawWeightPpbDecimal, (string) self::PPB_SCALE, 0);
        $achievedRatioPpbDecimal = bcdiv($achievedNumerator, $positiveObservedWeightPpbDecimal, 0);
        $achievedRatio = $this->percentageFromDecimalString($achievedRatioPpbDecimal);

        return new CoverageTargetResult(
            mathematicalMinimumCopyAmount: $mathematicalMinimumCopyAmount,
            effectiveMinimumCopyAmount: $effectiveMinimumCopyAmount,
            targetRatio: $request->targetCoverage,
            achievedRatio: $achievedRatio,
            coveredRawWeight: $coveredRawWeight,
            hasIncompleteSourceData: $hasIncompleteSourceData,
            warnings: $warnings,
        );
    }

    /**
     * Single shared data-quality warning detector used by both evaluate()
     * and minimumAmountForCoverage(), so the same snapshot always produces
     * the same warning set regardless of which entry point is called.
     * Building the list in canonical-enum order, appending each case at
     * most once, keeps the result both unique and canonically ordered by
     * construction.
     *
     * @param  list<PositionAllocation>  $positions
     * @return list<CoverageWarning>
     */
    private function detectDataQualityWarnings(array $positions, int $unmodeledEntryCount): array
    {
        $warnings = [];

        $seenPositionIds = [];
        $hasDuplicate = false;
        $hasNegative = false;
        $hasPositive = false;
        $totalWeightPpbDecimal = '0';

        foreach ($positions as $position) {
            if (isset($seenPositionIds[$position->positionId])) {
                $hasDuplicate = true;
            }

            $seenPositionIds[$position->positionId] = true;

            $weightPpb = $position->weight->partsPerBillion();
            $totalWeightPpbDecimal = bcadd($totalWeightPpbDecimal, (string) $weightPpb, 0);

            if ($position->weight->isNegative()) {
                $hasNegative = true;
            }

            if ($position->weight->isPositive()) {
                $hasPositive = true;
            }
        }

        if ($hasDuplicate) {
            $warnings[] = CoverageWarning::DuplicatePositionId;
        }

        if ($hasNegative) {
            $warnings[] = CoverageWarning::NegativeWeightIgnored;
        }

        if (bccomp($totalWeightPpbDecimal, (string) self::PPB_SCALE, 0) !== 0) {
            $warnings[] = CoverageWarning::ObservedWeightNotWhole;
        }

        if ($unmodeledEntryCount > 0) {
            $warnings[] = CoverageWarning::UnmodeledPortfolioEntriesPresent;
        }

        if ($positions !== [] && ! $hasPositive) {
            $warnings[] = CoverageWarning::NoPositiveWeightObserved;
        }

        return $warnings;
    }

    /**
     * Exact eligibility formula (weight is guaranteed positive by every
     * caller): copyAmountCents * weightPpb >= minimumPositionCents * whole,
     * computed and compared with BCMath end-to-end — never plain PHP
     * multiplication, which could silently overflow to float.
     */
    private function isEligible(int $copyAmountCents, int $weightPpb, int $minimumPositionCents): bool
    {
        $left = bcmul((string) $copyAmountCents, (string) $weightPpb, 0);
        $right = bcmul((string) $minimumPositionCents, (string) self::PPB_SCALE, 0);

        return bccomp($left, $right, 0) >= 0;
    }

    /**
     * minimumCopyAmountForEligibility = ceil(minimumPositionCents * whole /
     * weightPpb) for a positive weight, as an exact positive-integer BCMath
     * ceiling division (bcdiv floor + bcmod remainder check), never float
     * division or ceil().
     *
     * @return numeric-string
     */
    private function breakpointDecimalString(int $weightPpb, int $minimumPositionCents): string
    {
        $numerator = bcmul((string) $minimumPositionCents, (string) self::PPB_SCALE, 0);

        return $this->exactCeilDivision($numerator, (string) $weightPpb);
    }

    /**
     * Exact ceiling division of two non-negative BCMath decimal integer
     * strings, with a strictly positive denominator.
     *
     * @param  numeric-string  $numerator
     * @param  numeric-string  $denominator
     * @return numeric-string
     */
    private function exactCeilDivision(string $numerator, string $denominator): string
    {
        $quotient = bcdiv($numerator, $denominator, 0);
        $remainder = bcmod($numerator, $denominator, 0);

        if (bccomp($remainder, '0', 0) !== 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return $quotient;
    }

    /**
     * @param  numeric-string  $decimal
     */
    private function moneyFromDecimalString(string $decimal): Money
    {
        if (bccomp($decimal, '0', 0) < 0 || bccomp($decimal, (string) PHP_INT_MAX, 0) > 0) {
            throw CoverageCalculationException::moneyResultOutOfRange();
        }

        return Money::fromCents((int) $decimal);
    }

    /**
     * @param  numeric-string  $decimal
     */
    private function percentageFromDecimalString(string $decimal): Percentage
    {
        return Percentage::fromPartsPerBillion((int) $this->assertWithinSignedIntRange($decimal));
    }

    /**
     * @param  numeric-string  $decimal
     * @return numeric-string
     */
    private function assertWithinSignedIntRange(string $decimal): string
    {
        if (bccomp($decimal, (string) PHP_INT_MIN, 0) < 0 || bccomp($decimal, (string) PHP_INT_MAX, 0) > 0) {
            throw CoverageCalculationException::percentageResultOutOfRange();
        }

        return $decimal;
    }
}
