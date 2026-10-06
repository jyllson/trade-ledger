<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Calculators\CopySimulationCalculator;
use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\CoverageWarning;
use App\Analytics\Data\PositionSkipReason;
use App\Analytics\Data\SimulatedPosition;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\LivePortfolio;
use App\Models\CopySimulation;
use App\Models\PortfolioSnapshot;

/**
 * Simulates one copy amount over a STORED portfolio snapshot and persists
 * it as a `copy_simulations` row (PROJECT.md Flow D, §12;
 * docs/DECISIONS.md D-042). Never calls the eToro API.
 *
 * The stored `result` is a pure function of the snapshot content, the
 * inputs and METHODOLOGY_VERSION — no clock, no instrument metadata, no
 * staleness — so recalculate() reproduces it exactly. A methodology change
 * means a new version; rows of an older version are kept and never
 * recalculated with the new rules.
 */
final class SimulateCopyAmount
{
    public function __construct(
        private readonly StoredPortfolioCoverageAdapter $adapter,
        private readonly CopySimulationCalculator $calculator,
    ) {}

    /**
     * @param  Money|null  $minimumPositionAmount  default $1 (§11)
     * @param  Percentage|null  $targetCoverage  optional, relative to the visible positive weight (D-022)
     */
    public function handle(
        PortfolioSnapshot $snapshot,
        Money $copyAmount,
        ?Money $minimumPositionAmount = null,
        ?Percentage $targetCoverage = null,
    ): CopySimulation {
        $minimumPositionAmount ??= CopySimulationSettings::defaultMinimumPositionAmount();
        $platformMinimumCopyAmount = CopySimulationSettings::platformMinimumCopyAmount();

        $simulation = $this->simulate($snapshot, $copyAmount, $minimumPositionAmount, $platformMinimumCopyAmount, $targetCoverage);

        return CopySimulation::create([
            'trader_id' => $snapshot->trader_id,
            'portfolio_snapshot_id' => $snapshot->id,
            'copy_amount_cents' => $copyAmount->cents(),
            'minimum_position_amount_cents' => $minimumPositionAmount->cents(),
            'platform_minimum_copy_amount_cents' => $platformMinimumCopyAmount->cents(),
            'target_coverage_ppb' => $targetCoverage?->partsPerBillion(),
            'eligible_positions_count' => $simulation['result']->coverage->eligibleCount,
            'skipped_positions_count' => $simulation['result']->coverage->skippedCount,
            'eligible_weight_ppb' => $simulation['result']->coverage->coveredWeight->partsPerBillion(),
            'skipped_weight_ppb' => $simulation['result']->coverage->skippedWeight->partsPerBillion(),
            'cash_weight_ppb' => $snapshot->cash_weight_ppb,
            'minimum_target_amount_cents' => $simulation['result']->target?->effectiveMinimumCopyAmount?->cents(),
            'methodology_version' => CopySimulationSettings::METHODOLOGY_VERSION,
            'result' => $simulation['document'],
            'calculated_at' => now(),
        ]);
    }

    /**
     * The result document recomputed from the stored snapshot and the
     * row's own inputs; equal to the stored `result` for a row of the
     * current methodology.
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedCopySimulationMethodology for a row of another version
     */
    public function recalculate(CopySimulation $simulation): array
    {
        if ($simulation->methodology_version !== CopySimulationSettings::METHODOLOGY_VERSION) {
            throw UnsupportedCopySimulationMethodology::forVersion($simulation->methodology_version);
        }

        $snapshot = PortfolioSnapshot::query()->findOrFail($simulation->portfolio_snapshot_id);

        return $this->simulate(
            $snapshot,
            Money::fromCents($simulation->copy_amount_cents),
            Money::fromCents($simulation->minimum_position_amount_cents),
            Money::fromCents($simulation->platform_minimum_copy_amount_cents),
            $simulation->target_coverage_ppb === null ? null : Percentage::fromPartsPerBillion($simulation->target_coverage_ppb),
        )['document'];
    }

    /**
     * Whether recalculation gives exactly the stored `result`. Values are
     * compared strictly; object keys are compared order-independently,
     * because MySQL's JSON type re-orders them on storage (list order is
     * kept and compared).
     *
     * @throws UnsupportedCopySimulationMethodology for a row of another version
     */
    public function reproduces(CopySimulation $simulation): bool
    {
        return self::canonical($this->recalculate($simulation)) === self::canonical($simulation->result);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @return array{result: CopySimulationResult, document: array<string, mixed>}
     */
    private function simulate(
        PortfolioSnapshot $snapshot,
        Money $copyAmount,
        Money $minimumPositionAmount,
        Money $platformMinimumCopyAmount,
        ?Percentage $targetCoverage,
    ): array {
        $portfolio = $this->adapter->toLivePortfolio($snapshot);

        $result = $this->calculator->simulate(
            $this->adapter->toCopyCoverageRequest($portfolio, $copyAmount, $minimumPositionAmount),
            $portfolio->cashWeight,
            $platformMinimumCopyAmount,
            $targetCoverage,
        );

        return ['result' => $result, 'document' => $this->document($snapshot, $portfolio, $result)];
    }

    /**
     * Only ints, strings, bools and null — no floats — so the document
     * encodes identically on every recalculation.
     *
     * @return array<string, mixed>
     */
    private function document(PortfolioSnapshot $snapshot, LivePortfolio $portfolio, CopySimulationResult $result): array
    {
        $coverage = $result->coverage;

        return [
            'methodology_version' => CopySimulationSettings::METHODOLOGY_VERSION,
            'snapshot' => [
                'id' => $snapshot->id,
                'captured_at' => $snapshot->captured_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'source_hash' => $snapshot->source_hash,
                'position_count' => count($portfolio->positions),
                'social_trades_count' => $portfolio->socialTradesCount,
            ],
            'inputs' => [
                'copy_amount_cents' => $result->copyAmount->cents(),
                'minimum_position_amount_cents' => $result->minimumPositionAmount->cents(),
                'platform_minimum_copy_amount_cents' => $result->platformMinimumCopyAmount->cents(),
                'target_coverage_ppb' => $result->target?->targetRatio->partsPerBillion(),
            ],
            'summary' => [
                'eligible_positions_count' => $coverage->eligibleCount,
                'skipped_positions_count' => $coverage->skippedCount,
                'total_positions_count' => $coverage->eligibleCount + $coverage->skippedCount,
                'eligible_weight_ppb' => $coverage->coveredWeight->partsPerBillion(),
                'skipped_weight_ppb' => $coverage->skippedWeight->partsPerBillion(),
                'visible_position_weight_ppb' => $coverage->totalObservedWeight->partsPerBillion(),
                'positive_position_weight_ppb' => $coverage->positiveObservedWeight->partsPerBillion(),
                'coverage_of_positive_weight_ppb' => $result->coverageOfPositiveWeight?->partsPerBillion(),
                'cash_weight_ppb' => $result->cashWeight?->partsPerBillion(),
                'unknown_weight_ppb' => $result->unaccountedWeight?->partsPerBillion(),
                'below_platform_minimum' => $result->isBelowPlatformMinimum(),
                'is_estimate' => $result->isEstimate,
            ],
            'target' => $result->target === null ? null : $this->targetDocument($result->target),
            'positions' => array_map(
                fn (SimulatedPosition $position): array => $this->positionDocument($position, $portfolio, $result),
                $result->positions,
            ),
            'warnings' => array_map(
                fn (CopySimulationWarning $warning): array => ['code' => $warning->value, 'message' => $this->warningMessage($warning, $result)],
                $result->warnings,
            ),
            'calculator_warnings' => array_map(fn (CoverageWarning $warning): string => $warning->value, $coverage->warnings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetDocument(CoverageTargetResult $target): array
    {
        return [
            'target_coverage_ppb' => $target->targetRatio->partsPerBillion(),
            'is_reachable' => $target->effectiveMinimumCopyAmount !== null,
            'is_informational' => $target->targetRatio->compareTo(Percentage::whole()) === 0,
            'mathematical_minimum_amount_cents' => $target->mathematicalMinimumCopyAmount?->cents(),
            'minimum_amount_cents' => $target->effectiveMinimumCopyAmount?->cents(),
            'achieved_coverage_ppb' => $target->achievedRatio?->partsPerBillion(),
            'covered_weight_ppb' => $target->coveredRawWeight?->partsPerBillion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function positionDocument(SimulatedPosition $position, LivePortfolio $portfolio, CopySimulationResult $result): array
    {
        $outcome = $position->outcome;

        return [
            'index' => $position->index,
            'position_id' => $outcome->positionId,
            'instrument_id' => $portfolio->positions[$position->index]->instrumentId,
            'weight_ppb' => $outcome->weight->partsPerBillion(),
            'estimated_amount_cents' => $position->estimatedAmount?->cents(),
            'minimum_copy_amount_cents' => $outcome->minimumCopyAmountForEligibility?->cents(),
            'eligible' => $outcome->eligible,
            'skip_reason' => $outcome->reason?->value,
            'explanation' => $outcome->reason === null ? null : $this->skipExplanation($position, $result),
        ];
    }

    private function skipExplanation(SimulatedPosition $position, CopySimulationResult $result): string
    {
        $outcome = $position->outcome;

        return match ($outcome->reason) {
            PositionSkipReason::BelowMinimum => sprintf(
                'At a copy amount of %s this position (%s of the portfolio) would be about %s, below the %s minimum position amount, so it is not copied. It is copied from a copy amount of %s.',
                self::usd($result->copyAmount),
                self::percent($outcome->weight),
                self::usd($position->estimatedAmount ?? Money::zero()),
                self::usd($result->minimumPositionAmount),
                self::usd($outcome->minimumCopyAmountForEligibility ?? Money::zero()),
            ),
            PositionSkipReason::ZeroWeight => 'This position has a 0% weight in the snapshot, so no amount is allocated to it and it is not copied.',
            PositionSkipReason::NegativeWeight => sprintf(
                'This position has a negative weight (%s) in the snapshot. That is invalid source data, so it is ignored and not copied.',
                self::percent($outcome->weight),
            ),
            null => '',
        };
    }

    private function warningMessage(CopySimulationWarning $warning, CopySimulationResult $result): string
    {
        return match ($warning) {
            CopySimulationWarning::EmptySnapshot => 'The stored snapshot has no open positions, so nothing can be copied.',
            CopySimulationWarning::NoPositiveWeight => 'No position in the snapshot has a positive weight, so coverage cannot be calculated.',
            CopySimulationWarning::DuplicatePositionId => 'The snapshot repeats a position id; every entry is simulated as received, so the result is an estimate.',
            CopySimulationWarning::NegativeWeightIgnored => 'At least one position has a negative weight and is ignored, so the result is an estimate.',
            CopySimulationWarning::UnmodeledEntriesPresent => 'The portfolio holds copied-trader entries that are not modelled; their weight is not simulated, so the result is an estimate.',
            CopySimulationWarning::CashWeightUnknown => 'The snapshot does not report a cash weight, so cash and unknown weight cannot be told apart; the result is an estimate.',
            CopySimulationWarning::UnaccountedWeight => sprintf(
                'Positions and cash leave %s of the portfolio unaccounted for, so the result is an estimate.',
                self::percent($result->unaccountedWeight ?? Percentage::zero()),
            ),
            CopySimulationWarning::CopyAmountBelowPlatformMinimum => sprintf(
                'The copy amount is below the %s minimum copy amount of the platform.',
                self::usd($result->platformMinimumCopyAmount),
            ),
        };
    }

    /**
     * "$1,234.56" — exact, from integer cents.
     */
    private static function usd(Money $money): string
    {
        $digits = str_pad((string) abs($money->cents()), 3, '0', STR_PAD_LEFT);

        return sprintf(
            '%s$%s.%s',
            $money->isNegative() ? '-' : '',
            strrev(implode(',', str_split(strrev(substr($digits, 0, -2)), 3))),
            substr($digits, -2),
        );
    }

    /**
     * Exact percentage points from ppb ("0.1%", "33.3333333%").
     */
    private static function percent(Percentage $percentage): string
    {
        $ppb = $percentage->partsPerBillion();
        $digits = str_pad((string) abs($ppb), 8, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -7), '0');

        return sprintf('%s%s%s%%', $ppb < 0 ? '-' : '', substr($digits, 0, -7), $fraction === '' ? '' : '.'.$fraction);
    }
}
