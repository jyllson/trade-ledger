<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\Calculators\DataCompletenessCalculator;
use App\Analytics\Calculators\ReturnDistributionCalculator;
use App\Analytics\Data\CompletenessCheck;
use App\Analytics\Data\CompletenessState;
use App\Analytics\Data\CompletenessUnsupportedReason;
use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\DataCompletenessResult;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\AnalysisProfiles\AnalysisProfileCriteria;
use App\Application\AnalysisProfiles\ResolveDefaultAnalysisProfile;
use App\Application\Traders\BuildCopySimulationMatrix;
use App\Application\Traders\BuildTraderPerformanceReport;
use App\Application\Traders\BuildTraderPortfolioReport;
use App\Application\Traders\CopyAmountPreset;
use App\Application\Traders\CopySimulationMatrix;
use App\Application\Traders\CoverageTargetPreset;
use App\Application\Traders\EvaluateTraderProfileFreshness;
use App\Application\Traders\ProfileFreshness;
use App\Application\Traders\SyncTraderPerformance;
use App\Application\Traders\SyncTraderPortfolio;
use App\Application\Traders\TraderPerformanceReport;
use App\Application\Traders\TraderPerformanceSeriesReport;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Trader comparison read model (PROJECT.md Flow E, §14, §15, §20 M5;
 * docs/DECISIONS.md D-047).
 *
 * For 2–10 distinct, existing traders it returns every §14 metric as an
 * independent value with an explicit status (available / partial /
 * unavailable + reason) and its own observation period, plus the
 * comparison-level period alignment. It never combines metrics into a
 * score (§14 "No overall score").
 *
 * Reads STORED rows only through the existing read models and calculators:
 * never calls the eToro API and never writes (copy figures come from
 * BuildCopySimulationMatrix, which persists nothing).
 *
 * Every comparison is made under one analysis profile (D-048) — the given
 * one, else the stored default, else the built-in default (read-only, never
 * created here): its budget and target add copyability metrics, and its
 * criteria are evaluated per trader by EvaluateProfileFilters.
 */
final class BuildTraderComparison
{
    /** v2: profile-budget copyability metrics and profile filters (D-048). */
    public const METHODOLOGY_VERSION = 'comparison-v2';

    public const MINIMUM_TRADERS = 2;

    public const MAXIMUM_TRADERS = 10;

    /** A source is stale strictly after this many hours since its last successful sync. */
    public const STALE_AFTER_HOURS = 48;

    /** Failed import runs are counted over the last N days before the comparison instant. */
    public const FAILED_RUN_WINDOW_DAYS = 7;

    /** §13.8 "at least 24 monthly performance points" — counted as COMPLETE months. */
    public const COMPLETENESS_MONTHLY_POINTS = 24;

    public function __construct(
        private readonly BuildTraderPerformanceReport $performanceReport,
        private readonly BuildTraderPortfolioReport $portfolioReport,
        private readonly BuildCopySimulationMatrix $copySimulationMatrix,
        private readonly ReturnDistributionCalculator $distributionCalculator,
        private readonly DataCompletenessCalculator $completenessCalculator,
        private readonly EvaluateTraderProfileFreshness $profileFreshness,
        private readonly ResolveDefaultAnalysisProfile $defaultProfile,
        private readonly EvaluateProfileFilters $profileFilters,
    ) {}

    /**
     * @param  list<int>  $traderIds  in display order
     * @param  AnalysisProfileCriteria|null  $profile  null = the default profile (D-048)
     *
     * @throws TraderComparisonRejected
     */
    public function handle(array $traderIds, ?CarbonInterface $now = null, ?AnalysisProfileCriteria $profile = null): TraderComparison
    {
        // Every instant is UTC (D-046): a caller's display-zone `now` must
        // not shift the import-run window or any classification.
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $traders = $this->resolveTraders($traderIds);
        $profile ??= $this->defaultProfile->handle();

        // Points and import runs of all traders are read in one query each;
        // the latest snapshot is read once per trader (D-047 point 9).
        $performance = $this->performanceReport->handleMany($traders);
        $runs = $this->importRunsInWindow($traders, $now);

        $entries = array_map(
            fn (Trader $trader): TraderComparisonEntry => $this->entry($trader, $now, $performance[$trader->id], $runs[$trader->id] ?? [], $profile),
            $traders,
        );

        $monthly = [];
        $daily = [];
        $snapshots = [];

        foreach ($entries as $entry) {
            $monthly[$entry->traderId] = $entry->monthlyObservation;
            $daily[$entry->traderId] = $entry->dailyObservation;
            $snapshots[$entry->traderId] = $entry->snapshotObservation;
        }

        return new TraderComparison(
            methodologyVersion: self::METHODOLOGY_VERSION,
            generatedAt: $now->toDateTimeImmutable(),
            staleAfterHours: self::STALE_AFTER_HOURS,
            failedRunWindowDays: self::FAILED_RUN_WINDOW_DAYS,
            entries: $entries,
            periods: new ComparisonPeriods(
                monthly: SeriesAlignment::of(ReturnPeriodGranularity::Monthly, $monthly),
                daily: SeriesAlignment::of(ReturnPeriodGranularity::Daily, $daily),
                snapshots: SnapshotAlignment::of($snapshots),
            ),
            profile: $profile,
        );
    }

    /**
     * @param  list<int>  $traderIds
     * @return list<Trader>
     */
    private function resolveTraders(array $traderIds): array
    {
        if (count($traderIds) < self::MINIMUM_TRADERS) {
            throw new TraderComparisonRejected(TraderComparisonRejectionReason::TooFewTraders);
        }

        if (count($traderIds) > self::MAXIMUM_TRADERS) {
            throw new TraderComparisonRejected(TraderComparisonRejectionReason::TooManyTraders);
        }

        $duplicates = array_keys(array_filter(array_count_values($traderIds), static fn (int $count): bool => $count > 1));

        if ($duplicates !== []) {
            throw new TraderComparisonRejected(TraderComparisonRejectionReason::DuplicateTrader, $duplicates);
        }

        $found = Trader::query()->whereKey($traderIds)->get()->keyBy('id');
        $unknown = array_values(array_filter($traderIds, static fn (int $id): bool => ! $found->has($id)));

        if ($unknown !== []) {
            throw new TraderComparisonRejected(TraderComparisonRejectionReason::UnknownTrader, $unknown);
        }

        return array_map(static fn (int $id): Trader => $found->get($id), $traderIds);
    }

    /**
     * @param  list<ImportRun>  $importRuns  this trader's performance/portfolio runs in the failed-run window
     */
    private function entry(Trader $trader, CarbonImmutable $now, TraderPerformanceReport $performance, array $importRuns, AnalysisProfileCriteria $profile): TraderComparisonEntry
    {
        $portfolio = $this->portfolioReport->handle($trader);
        $matrix = $portfolio->snapshot === null
            ? null
            : $this->copySimulationMatrix->handle($portfolio->snapshot, positions: $portfolio->positions);
        $budgetSimulation = $portfolio->snapshot === null
            ? null
            : $this->copySimulationMatrix->simulateAmount($portfolio->snapshot, $profile->budget, $profile->targetCoverage, positions: $portfolio->positions);

        $performanceFreshness = $this->freshness($trader->performance_visibility, $trader->performance_synced_at, $now);
        $portfolioFreshness = $this->freshness($trader->portfolio_visibility, $trader->portfolio_synced_at, $now);

        $context = new TraderComparisonContext(
            trader: $trader,
            now: $now,
            monthly: $performance->monthly,
            daily: $performance->daily,
            portfolio: $portfolio,
            matrix: $matrix,
            performanceFreshness: $performanceFreshness,
            portfolioFreshness: $portfolioFreshness,
            importRuns: $importRuns,
            noData: ObservationPeriod::noData($now->toDateTimeImmutable()),
            snapshotObservation: $portfolio->snapshot === null ? null : new ObservationPeriod(
                basis: ObservationBasis::PortfolioSnapshot,
                granularity: null,
                from: $portfolio->snapshot->captured_at->toDateTimeImmutable(),
                to: $portfolio->snapshot->last_confirmed_at->toDateTimeImmutable(),
                pointCount: 1,
            ),
            profile: $profile,
            budgetSimulation: $budgetSimulation,
        );

        $metrics = [];

        foreach ([
            ...$this->performanceMetrics($context),
            ...$this->riskMetrics($context),
            ...$this->consistencyMetrics($context),
            ...$this->copyabilityMetrics($context),
            ...$this->dataQualityMetrics($context),
        ] as $metric) {
            $metrics[$metric->key->value] = $metric;
        }

        $ordered = [];

        foreach (ComparisonMetricKey::cases() as $key) {
            $ordered[$key->value] = $metrics[$key->value] ?? throw new LogicException(sprintf('Comparison metric "%s" was not built.', $key->value));
        }

        $entry = new TraderComparisonEntry(
            traderId: $trader->id,
            username: $trader->username,
            performanceVisibility: $trader->performance_visibility,
            portfolioVisibility: $trader->portfolio_visibility,
            monthlyObservation: $this->wholeSeries($performance->monthly),
            dailyObservation: $this->wholeSeries($performance->daily),
            snapshotObservation: $context->snapshotObservation,
            portfolioSnapshotId: $portfolio->snapshot?->id,
            metrics: $ordered,
        );

        return $entry->withProfileFilters($this->profileFilters->evaluate($profile, $entry));
    }

    /**
     * @return list<ComparisonMetric>
     */
    private function performanceMetrics(TraderComparisonContext $context): array
    {
        $monthly = $context->monthly;

        if ($monthly === null) {
            return $this->noSeries($context, ReturnPeriodGranularity::Monthly, [
                ComparisonMetricKey::CumulativeReturn,
                ComparisonMetricKey::Trailing12MonthReturn,
                ComparisonMetricKey::Trailing24MonthReturn,
                ComparisonMetricKey::AverageMonthlyReturn,
                ComparisonMetricKey::MedianMonthlyReturn,
                ComparisonMetricKey::ProfitableMonthRatio,
            ]);
        }

        $summary = $monthly->summary;
        $warnings = $this->performanceWarnings($context);
        $all = $monthly->series->periods;

        return [
            ComparisonMetric::available(
                ComparisonMetricKey::CumulativeReturn,
                $this->required($summary->cumulativeReturn),
                ObservationPeriod::ofPeriods(ReturnPeriodGranularity::Monthly, $all),
                [...$this->seriesBoundaryWarnings($all), ...$warnings],
            ),
            $this->trailing($context, ComparisonMetricKey::Trailing12MonthReturn, $monthly, 12, $summary->trailing12Return, $warnings),
            $this->trailing($context, ComparisonMetricKey::Trailing24MonthReturn, $monthly, 24, $summary->trailing24Return, $warnings),
            $this->overCompletePeriods($context, ComparisonMetricKey::AverageMonthlyReturn, $monthly, $summary->averageReturn, 1, $warnings),
            $this->overCompletePeriods($context, ComparisonMetricKey::MedianMonthlyReturn, $monthly, $summary->medianReturn, 1, $warnings),
            $this->overCompletePeriods($context, ComparisonMetricKey::ProfitableMonthRatio, $monthly, $monthly->consistency->positiveRatio, 1, $warnings, $this->monthCounts($monthly)),
        ];
    }

    /**
     * @return list<ComparisonMetric>
     */
    private function riskMetrics(TraderComparisonContext $context): array
    {
        $metrics = [
            $this->maxDrawdown(ComparisonMetricKey::DailyMaxDrawdown, ReturnPeriodGranularity::Daily, $context->daily, $context),
            $this->maxDrawdown(ComparisonMetricKey::MonthlyMaxDrawdown, ReturnPeriodGranularity::Monthly, $context->monthly, $context),
        ];

        if ($context->monthly === null) {
            $metrics = [...$metrics, ...$this->noSeries($context, ReturnPeriodGranularity::Monthly, [
                ComparisonMetricKey::MonthlyVolatility,
                ComparisonMetricKey::AnnualizedVolatility,
            ])];
        } else {
            $warnings = $this->performanceWarnings($context);
            $metrics[] = $this->overCompletePeriods($context, ComparisonMetricKey::MonthlyVolatility, $context->monthly, $context->monthly->consistency->volatility, 2, $warnings, ['annualized' => false]);
            $metrics[] = $this->overCompletePeriods($context, ComparisonMetricKey::AnnualizedVolatility, $context->monthly, $context->monthly->consistency->annualizedVolatility, 2, $warnings, ['annualized' => true, 'annualization_factor' => 'sqrt(12)']);
        }

        // eToro's risk score is not collected by the application (no stored
        // field or endpoint) — shown as unavailable, never estimated, as
        // evaluated at the comparison instant.
        $metrics[] = ComparisonMetric::unavailable(
            ComparisonMetricKey::RiskScore,
            MetricUnavailableReason::NotProvidedBySource,
            ObservationPeriod::evaluatedAt($context->now->toDateTimeImmutable()),
        );

        return [...$metrics, ...$this->exposureMetrics($context)];
    }

    /**
     * Largest position, top-3 concentration (by instrument, D-041) and
     * weighted leverage (§13.7, D-041 point 5) of the latest snapshot.
     *
     * @return list<ComparisonMetric>
     */
    private function exposureMetrics(TraderComparisonContext $context): array
    {
        $exposure = $context->portfolio->exposure;
        $keys = [ComparisonMetricKey::LargestPosition, ComparisonMetricKey::TopThreeConcentration, ComparisonMetricKey::WeightedLeverage];

        if ($exposure === null) {
            return array_map(fn (ComparisonMetricKey $key): ComparisonMetric => $this->noSnapshot($key, $context), $keys);
        }

        $observation = $context->snapshotObservation ?? $context->noData;
        $warnings = $this->snapshotWarnings($context);
        $concentration = $exposure->concentration;
        $byInstrument = $concentration->byInstrument;
        $excluded = $concentration->missingWeightCount + $concentration->negativeWeightCount;
        $basis = [
            'weight_basis' => $concentration->weightBasis->value,
            'invested_weight' => $concentration->investedWeight,
            'cash_weight' => $concentration->cashWeight,
            'excluded_position_count' => $excluded,
        ];
        $concentrationWarnings = $excluded > 0 ? [...$warnings, MetricWarning::PositionsWithoutUsableWeightExcluded] : $warnings;

        $metrics = [];

        if ($byInstrument->status === ExposureStatus::Unavailable) {
            foreach ([ComparisonMetricKey::LargestPosition, ComparisonMetricKey::TopThreeConcentration] as $key) {
                $metrics[] = ComparisonMetric::unavailable($key, $this->exposureReason($byInstrument->unavailableReason), $observation, $warnings, $basis);
            }
        } else {
            $partial = $byInstrument->status === ExposureStatus::Partial || $excluded > 0;
            $metrics[] = ComparisonMetric::available(
                ComparisonMetricKey::LargestPosition,
                $this->required($byInstrument->largest?->weight),
                $observation,
                $concentrationWarnings,
                [...$basis, 'instrument_id' => $byInstrument->largest?->key, 'instrument_position_count' => $byInstrument->largest?->positionCount],
                $partial,
            );
            $metrics[] = ComparisonMetric::available(
                ComparisonMetricKey::TopThreeConcentration,
                $this->required($byInstrument->topThreeWeight),
                $observation,
                $concentrationWarnings,
                [...$basis, 'instrument_count' => count($byInstrument->groups)],
                $partial,
            );
        }

        $leverage = $exposure->leverage;
        $leverageDetails = [
            'weight_basis' => $leverage->weightBasis->value,
            'known_leverage_contribution' => $leverage->knownLeverageContribution,
            'known_leverage_weight' => $leverage->knownLeverageWeight,
            'unknown_leverage_weight' => $leverage->unknownLeverageWeight,
            'leveraged_weight' => $leverage->leveragedWeight,
            'max_leverage' => $leverage->maxLeverage,
            'leveraged_position_count' => $leverage->leveragedPositionCount,
            'excluded_position_count' => $leverage->missingWeightCount + $leverage->negativeWeightCount,
        ];
        $leverageWarnings = $leverage->missingWeightCount + $leverage->negativeWeightCount > 0
            ? [...$warnings, MetricWarning::PositionsWithoutUsableWeightExcluded]
            : $warnings;

        $metrics[] = match (true) {
            $leverage->status === ExposureStatus::Unavailable => ComparisonMetric::unavailable(
                ComparisonMetricKey::WeightedLeverage,
                $this->exposureReason($leverage->unavailableReason),
                $observation,
                $leverageWarnings,
                $leverageDetails,
            ),
            $leverage->weightedLeverage === null => ComparisonMetric::unavailable(
                ComparisonMetricKey::WeightedLeverage,
                MetricUnavailableReason::LeverageNotDeterminable,
                $observation,
                $leverageWarnings,
                $leverageDetails,
            ),
            default => ComparisonMetric::available(
                ComparisonMetricKey::WeightedLeverage,
                $leverage->weightedLeverage,
                $observation,
                $leverageWarnings,
                $leverageDetails,
                $leverage->status === ExposureStatus::Partial,
            ),
        };

        return $metrics;
    }

    /**
     * @return list<ComparisonMetric>
     */
    private function consistencyMetrics(TraderComparisonContext $context): array
    {
        $monthly = $context->monthly;

        if ($monthly === null) {
            return $this->noSeries($context, ReturnPeriodGranularity::Monthly, [
                ComparisonMetricKey::PositiveMonthRatio,
                ComparisonMetricKey::LongestLosingStreak,
                ComparisonMetricKey::MonthlyReturnDispersion,
                ComparisonMetricKey::BestMonthDependency,
                ComparisonMetricKey::ReturnExcludingBestMonth,
                ComparisonMetricKey::ReturnExcludingBestThreeMonths,
            ]);
        }

        $warnings = $this->performanceWarnings($context);
        $consistency = $monthly->consistency;
        $distribution = $this->distributionCalculator->calculate($monthly->series);
        $completeReturn = ['complete_cumulative_return' => $consistency->completeCumulativeReturn];

        $dependency = $this->overCompletePeriods(
            $context,
            ComparisonMetricKey::BestMonthDependency,
            $monthly,
            $distribution->bestPeriodShareOfReturn,
            2,
            $warnings,
            [
                ...$completeReturn,
                'best_month_return' => $consistency->bestReturn,
                'best_month_contribution' => $distribution->bestPeriodContribution,
                'methodology_version' => $distribution->methodologyVersion,
            ],
        );

        if ($distribution->bestPeriodShareOfReturn === null && $distribution->bestPeriodContribution !== null) {
            $dependency = ComparisonMetric::unavailable(
                ComparisonMetricKey::BestMonthDependency,
                MetricUnavailableReason::NonPositiveReturn,
                $dependency->observation,
                $warnings,
                $dependency->details,
            );
        }

        return [
            $this->overCompletePeriods($context, ComparisonMetricKey::PositiveMonthRatio, $monthly, $consistency->positiveRatio, 1, $warnings, $this->monthCounts($monthly)),
            $this->overCompletePeriods(
                $context,
                ComparisonMetricKey::LongestLosingStreak,
                $monthly,
                $consistency->observedCount === 0 ? null : $consistency->longestNegativeStreak,
                1,
                $warnings,
                ['longest_winning_streak' => $consistency->observedCount === 0 ? null : $consistency->longestPositiveStreak],
            ),
            $this->overCompletePeriods(
                $context,
                ComparisonMetricKey::MonthlyReturnDispersion,
                $monthly,
                $distribution->interquartileRange,
                ReturnDistributionCalculator::MINIMUM_DISPERSION_PERIODS,
                $warnings,
                [
                    'measure' => 'interquartile_range',
                    'first_quartile' => $distribution->firstQuartile,
                    'third_quartile' => $distribution->thirdQuartile,
                    'methodology_version' => $distribution->methodologyVersion,
                ],
            ),
            $dependency,
            $this->overCompletePeriods($context, ComparisonMetricKey::ReturnExcludingBestMonth, $monthly, $consistency->returnExcludingBest, 2, $warnings, $completeReturn),
            $this->overCompletePeriods($context, ComparisonMetricKey::ReturnExcludingBestThreeMonths, $monthly, $consistency->returnExcludingBestThree, 4, $warnings, $completeReturn),
        ];
    }

    /**
     * Copy figures of the latest stored snapshot through the existing
     * simulator matrix (D-042/D-043), with the stale snapshot marked (D-045),
     * plus the same figures at the analysis profile's budget and target
     * (D-048) through the same simulator.
     *
     * @return list<ComparisonMetric>
     */
    private function copyabilityMetrics(TraderComparisonContext $context): array
    {
        $presets = [
            [CopyAmountPreset::Usd200, ComparisonMetricKey::CoverageAt200, ComparisonMetricKey::SkippedCountAt200, ComparisonMetricKey::SkippedWeightAt200],
            [CopyAmountPreset::Usd500, ComparisonMetricKey::CoverageAt500, ComparisonMetricKey::SkippedCountAt500, ComparisonMetricKey::SkippedWeightAt500],
            [CopyAmountPreset::Usd1000, ComparisonMetricKey::CoverageAt1000, ComparisonMetricKey::SkippedCountAt1000, ComparisonMetricKey::SkippedWeightAt1000],
        ];
        $targets = [
            [CoverageTargetPreset::Percent90, ComparisonMetricKey::MinimumFor90],
            [CoverageTargetPreset::Percent95, ComparisonMetricKey::MinimumFor95],
            [CoverageTargetPreset::Percent99, ComparisonMetricKey::MinimumFor99],
            [CoverageTargetPreset::Percent100, ComparisonMetricKey::MinimumForAllVisible],
        ];
        $profileKeys = [
            ComparisonMetricKey::CoverageAtProfileBudget,
            ComparisonMetricKey::SkippedCountAtProfileBudget,
            ComparisonMetricKey::SkippedWeightAtProfileBudget,
            ComparisonMetricKey::MinimumForProfileTarget,
        ];

        $matrix = $context->matrix;

        if ($matrix === null) {
            $keys = [...array_merge(...array_map(static fn (array $row): array => array_slice($row, 1), $presets)), ...array_column($targets, 1), ...$profileKeys];

            return array_map(fn (ComparisonMetricKey $key): ComparisonMetric => $this->noSnapshot($key, $context), $keys);
        }

        $metrics = [];

        foreach ($presets as [$preset, $coverageKey, $skippedCountKey, $skippedWeightKey]) {
            $metrics = [...$metrics, ...$this->amountMetrics($context, $matrix, $preset->amount(), $matrix->presets[$preset->value], $coverageKey, $skippedCountKey, $skippedWeightKey)];
        }

        foreach ($targets as [$target, $key]) {
            $metrics[] = $this->targetMetric($context, $matrix, $target->coverage(), $target->isInformational(), $matrix->targets[$target->value], $key);
        }

        $profile = $context->profile;
        $budget = $context->budgetSimulation;

        return [
            ...$metrics,
            ...$this->amountMetrics($context, $matrix, $profile->budget, $budget, ComparisonMetricKey::CoverageAtProfileBudget, ComparisonMetricKey::SkippedCountAtProfileBudget, ComparisonMetricKey::SkippedWeightAtProfileBudget),
            // An out-of-range budget simulation has no target result either.
            $this->targetMetric($context, $matrix, $profile->targetCoverage, $profile->targetCoverage->compareTo(Percentage::whole()) === 0, $budget?->target, ComparisonMetricKey::MinimumForProfileTarget),
        ];
    }

    /**
     * Coverage, skipped count and skipped weight of one copy amount.
     *
     * @param  CopySimulationResult|null  $result  null = out of the representable range (D-043)
     * @return list<ComparisonMetric>
     */
    private function amountMetrics(
        TraderComparisonContext $context,
        CopySimulationMatrix $matrix,
        Money $amount,
        ?CopySimulationResult $result,
        ComparisonMetricKey $coverageKey,
        ComparisonMetricKey $skippedCountKey,
        ComparisonMetricKey $skippedWeightKey,
    ): array {
        [$warnings, $partial] = $this->copyWarnings($context, $matrix);
        $observation = $context->snapshotObservation ?? $context->noData;
        $details = ['copy_amount' => $amount, 'portfolio_snapshot_id' => $matrix->portfolioSnapshotId];

        if ($result === null) {
            return array_map(
                static fn (ComparisonMetricKey $key): ComparisonMetric => ComparisonMetric::unavailable($key, MetricUnavailableReason::OutOfRange, $observation, $warnings, $details),
                [$coverageKey, $skippedCountKey, $skippedWeightKey],
            );
        }

        $coverage = $result->coverage;

        return [
            $result->coverageOfPositiveWeight === null
                ? ComparisonMetric::unavailable($coverageKey, MetricUnavailableReason::NoPositiveWeight, $observation, $warnings, $details)
                : ComparisonMetric::available(
                    $coverageKey,
                    $result->coverageOfPositiveWeight,
                    $observation,
                    $warnings,
                    [...$details, 'coverage_basis' => 'positive_position_weight', 'covered_portfolio_weight' => $coverage->coveredWeight, 'eligible_count' => $coverage->eligibleCount],
                    $partial,
                ),
            ComparisonMetric::available($skippedCountKey, $coverage->skippedCount, $observation, $warnings, $details, $partial),
            ComparisonMetric::available(
                $skippedWeightKey,
                $coverage->skippedWeight,
                $observation,
                $warnings,
                [...$details, 'weight_basis' => 'whole_portfolio'],
                $partial,
            ),
        ];
    }

    /**
     * The effective minimum copy amount for one coverage target.
     *
     * @param  CoverageTargetResult|null  $result  null = out of the representable range (D-043)
     */
    private function targetMetric(
        TraderComparisonContext $context,
        CopySimulationMatrix $matrix,
        Percentage $target,
        bool $informational,
        ?CoverageTargetResult $result,
        ComparisonMetricKey $key,
    ): ComparisonMetric {
        [$warnings, $partial] = $this->copyWarnings($context, $matrix);
        $observation = $context->snapshotObservation ?? $context->noData;
        $details = [
            'target_coverage' => $target,
            'informational' => $informational,
            'platform_minimum_copy_amount' => $matrix->platformMinimumCopyAmount,
            'minimum_position_amount' => $matrix->minimumPositionAmount,
            'portfolio_snapshot_id' => $matrix->portfolioSnapshotId,
        ];

        if ($result === null) {
            return ComparisonMetric::unavailable($key, MetricUnavailableReason::OutOfRange, $observation, $warnings, $details);
        }

        if ($result->effectiveMinimumCopyAmount === null) {
            return ComparisonMetric::unavailable($key, MetricUnavailableReason::NoPositiveWeight, $observation, $warnings, $details);
        }

        return ComparisonMetric::available(
            $key,
            $result->effectiveMinimumCopyAmount,
            $observation,
            $warnings,
            [...$details, 'mathematical_minimum_copy_amount' => $result->mathematicalMinimumCopyAmount, 'achieved_coverage' => $result->achievedRatio],
            $partial,
        );
    }

    /**
     * @return list<ComparisonMetric>
     */
    private function dataQualityMetrics(TraderComparisonContext $context): array
    {
        $trader = $context->trader;
        $evaluatedAt = ObservationPeriod::evaluatedAt($context->now->toDateTimeImmutable());

        $stale = ! $context->performanceFreshness->isFresh() || ! $context->portfolioFreshness->isFresh();
        $completeness = $this->completeness($context);

        return [
            $this->syncTimestamp(ComparisonMetricKey::PerformanceLastSuccessfulSync, $trader->performance_synced_at, $context->performanceFreshness, $context),
            $this->syncTimestamp(ComparisonMetricKey::PortfolioLastSuccessfulSync, $trader->portfolio_synced_at, $context->portfolioFreshness, $context),
            $this->visibility(ComparisonMetricKey::PerformanceVisibility, $trader->performance_visibility, $trader->performance_synced_at, $context),
            $this->visibility(ComparisonMetricKey::PortfolioVisibility, $trader->portfolio_visibility, $trader->portfolio_synced_at, $context),
            ComparisonMetric::available(
                ComparisonMetricKey::StaleDataWarning,
                $stale,
                $evaluatedAt,
                [],
                [
                    'performance_freshness' => $context->performanceFreshness->value,
                    'portfolio_freshness' => $context->portfolioFreshness->value,
                    'stale_after_hours' => self::STALE_AFTER_HOURS,
                ],
            ),
            ComparisonMetric::available(
                ComparisonMetricKey::CompletenessScore,
                $completeness->score,
                $evaluatedAt,
                [],
                [
                    'methodology_version' => $completeness->methodologyVersion,
                    'formula' => 'present_count / collectable_count',
                    'present_count' => $completeness->presentCount,
                    'collectable_count' => $completeness->collectableCount,
                    'total_count' => $completeness->totalCount,
                    'collectable_checks' => array_map(static fn (CompletenessState $state): string => $state->value, $completeness->checks),
                    'not_supported_checks' => array_map(static fn (CompletenessUnsupportedReason $reason): string => $reason->value, $completeness->notSupported),
                ],
            ),
            $this->failedEndpointCount($context),
        ];
    }

    private function completeness(TraderComparisonContext $context): DataCompletenessResult
    {
        $profile = $this->profileFreshness->handle($context->trader->profile_synced_at, $context->now);
        $completeMonths = $context->monthly === null ? 0 : $context->monthly->consistency->observedCount;

        return $this->completenessCalculator->calculate([
            CompletenessCheck::Profile->value => match ($profile) {
                ProfileFreshness::NeverSynced => CompletenessState::Missing,
                ProfileFreshness::Stale => CompletenessState::Stale,
                ProfileFreshness::Fresh => CompletenessState::Present,
            },
            CompletenessCheck::MonthlyHistory->value => $completeMonths < self::COMPLETENESS_MONTHLY_POINTS
                ? CompletenessState::Missing
                : $this->storedState($context->performanceFreshness),
            CompletenessCheck::DailyData->value => $context->daily === null
                ? CompletenessState::Missing
                : $this->storedState($context->performanceFreshness),
            CompletenessCheck::LivePortfolio->value => $context->portfolio->snapshot === null
                ? CompletenessState::Missing
                : $this->storedState($context->portfolioFreshness),
        ], [
            // No integration collects these sources yet — an application
            // limitation, the same for every trader, outside the score.
            CompletenessCheck::AssetHistory->value => CompletenessUnsupportedReason::NotCollectedByApplication,
            CompletenessCheck::ExposureHistory->value => CompletenessUnsupportedReason::NotCollectedByApplication,
            CompletenessCheck::TradeInfo->value => CompletenessUnsupportedReason::NotCollectedByApplication,
            CompletenessCheck::CopierHistory->value => CompletenessUnsupportedReason::NotCollectedByApplication,
        ]);
    }

    private function storedState(DataFreshness $freshness): CompletenessState
    {
        return $freshness->isFresh() ? CompletenessState::Present : CompletenessState::Stale;
    }

    /**
     * Performance / portfolio import runs of the compared traders started
     * within [now − FAILED_RUN_WINDOW_DAYS, now], read in one query and
     * grouped by `metadata.query.trader_id`. Profile lookups are keyed by
     * username, not trader id, and are not counted.
     *
     * @param  list<Trader>  $traders
     * @return array<int, list<ImportRun>> keyed by trader id
     */
    private function importRunsInWindow(array $traders, CarbonImmutable $now): array
    {
        $runs = ImportRun::query()
            ->whereIn('type', [SyncTraderPerformance::TYPE, SyncTraderPortfolio::TYPE])
            ->whereIn('metadata->query->trader_id', array_map(static fn (Trader $trader): int => $trader->id, $traders))
            ->whereBetween('started_at', [$now->subDays(self::FAILED_RUN_WINDOW_DAYS), $now])
            ->get(['type', 'status', 'metadata']);

        $byTrader = [];

        foreach ($runs as $run) {
            $traderId = data_get($run->metadata, 'query.trader_id');

            if (is_numeric($traderId)) {
                $byTrader[(int) $traderId][] = $run;
            }
        }

        return $byTrader;
    }

    /**
     * Failed runs among the trader's runs in the window (D-047).
     */
    private function failedEndpointCount(TraderComparisonContext $context): ComparisonMetric
    {
        $from = $context->now->subDays(self::FAILED_RUN_WINDOW_DAYS);
        $runs = collect($context->importRuns);
        $failed = $runs->filter(static fn (ImportRun $run): bool => $run->status === ImportRunStatus::Failed);

        return ComparisonMetric::available(
            ComparisonMetricKey::FailedEndpointCount,
            $failed->count(),
            new ObservationPeriod(ObservationBasis::ImportRunWindow, null, $from->toDateTimeImmutable(), $context->now->toDateTimeImmutable(), $runs->count()),
            [],
            [
                'window_days' => self::FAILED_RUN_WINDOW_DAYS,
                'failed_performance_runs' => $failed->where('type', SyncTraderPerformance::TYPE)->count(),
                'failed_portfolio_runs' => $failed->where('type', SyncTraderPortfolio::TYPE)->count(),
                'partial_runs' => $runs->filter(static fn (ImportRun $run): bool => $run->status === ImportRunStatus::Partial)->count(),
                'runs_in_window' => $runs->count(),
            ],
        );
    }

    private function syncTimestamp(ComparisonMetricKey $key, ?CarbonInterface $syncedAt, DataFreshness $freshness, TraderComparisonContext $context): ComparisonMetric
    {
        if ($syncedAt === null) {
            return ComparisonMetric::unavailable($key, MetricUnavailableReason::NeverSynced, $context->noData, [], ['freshness' => $freshness->value]);
        }

        $at = CarbonImmutable::instance($syncedAt)->utc()->toDateTimeImmutable();

        return ComparisonMetric::available($key, $at, ObservationPeriod::syncRecord($at), [], ['freshness' => $freshness->value]);
    }

    /**
     * The visibility observed by the source's last successful sync — its
     * period is that sync record; without one, the explicit empty period.
     */
    private function visibility(ComparisonMetricKey $key, ?PerformanceVisibility $visibility, ?CarbonInterface $syncedAt, TraderComparisonContext $context): ComparisonMetric
    {
        $observation = $syncedAt === null
            ? $context->noData
            : ObservationPeriod::syncRecord(CarbonImmutable::instance($syncedAt)->utc()->toDateTimeImmutable());

        return $visibility === null
            ? ComparisonMetric::unavailable($key, MetricUnavailableReason::NeverSynced, $observation)
            : ComparisonMetric::available($key, $visibility->value, $observation);
    }

    private function freshness(?PerformanceVisibility $visibility, ?CarbonInterface $syncedAt, CarbonImmutable $now): DataFreshness
    {
        if ($visibility === PerformanceVisibility::Private || $visibility === PerformanceVisibility::NotFound) {
            return DataFreshness::NoLongerVisible;
        }

        if ($syncedAt === null) {
            return DataFreshness::NeverSynced;
        }

        // Strictly after the threshold; a future timestamp (clock skew) is
        // never aged — same convention as EvaluateTraderProfileFreshness.
        if ($syncedAt->isAfter($now)) {
            return DataFreshness::Fresh;
        }

        return $now->diffInSeconds($syncedAt, absolute: true) > self::STALE_AFTER_HOURS * 3600
            ? DataFreshness::Stale
            : DataFreshness::Fresh;
    }

    /**
     * @return list<MetricWarning>
     */
    private function performanceWarnings(TraderComparisonContext $context): array
    {
        return match ($context->performanceFreshness) {
            DataFreshness::NoLongerVisible => [MetricWarning::PerformanceNoLongerVisible],
            DataFreshness::Stale, DataFreshness::NeverSynced => [MetricWarning::PerformanceStale],
            DataFreshness::Fresh => [],
        };
    }

    /**
     * @return list<MetricWarning>
     */
    private function snapshotWarnings(TraderComparisonContext $context): array
    {
        return match ($context->portfolioFreshness) {
            DataFreshness::NoLongerVisible => [MetricWarning::SnapshotNoLongerVisible],
            DataFreshness::Stale, DataFreshness::NeverSynced => [MetricWarning::SnapshotStale],
            DataFreshness::Fresh => [],
        };
    }

    /**
     * @return array{0: list<MetricWarning>, 1: bool}
     */
    private function copyWarnings(TraderComparisonContext $context, CopySimulationMatrix $matrix): array
    {
        $warnings = $this->snapshotWarnings($context);

        if ($matrix->isEstimate) {
            $warnings[] = MetricWarning::EstimatedFromIncompleteSnapshot;
        }

        return [$warnings, $matrix->isEstimate];
    }

    /**
     * @param  list<PeriodReturn>  $periods
     * @return list<MetricWarning>
     */
    private function seriesBoundaryWarnings(array $periods): array
    {
        $warnings = [];

        if ($periods !== [] && $periods[0]->isPartialStart) {
            $warnings[] = MetricWarning::IncludesPartialStartPeriod;
        }

        if ($periods !== [] && $periods[count($periods) - 1]->isInProgress) {
            $warnings[] = MetricWarning::IncludesInProgressPeriod;
        }

        return $warnings;
    }

    /**
     * @param  list<MetricWarning>  $warnings
     */
    private function trailing(TraderComparisonContext $context, ComparisonMetricKey $key, TraderPerformanceSeriesReport $monthly, int $months, ?Percentage $value, array $warnings): ComparisonMetric
    {
        $complete = $monthly->series->completePeriods();

        if ($value === null || count($complete) < $months) {
            return ComparisonMetric::unavailable(
                $key,
                MetricUnavailableReason::InsufficientHistory,
                $complete === [] ? $context->noData : ObservationPeriod::ofPeriods(ReturnPeriodGranularity::Monthly, $complete),
                $warnings,
                ['required_complete_periods' => $months, 'available_complete_periods' => count($complete)],
            );
        }

        return ComparisonMetric::available($key, $value, ObservationPeriod::ofPeriods(ReturnPeriodGranularity::Monthly, array_slice($complete, -$months)), $warnings);
    }

    /**
     * A metric over the COMPLETE periods of a series (D-033); unavailable
     * with InsufficientHistory when the calculator returned no value.
     *
     * @param  list<MetricWarning>  $warnings
     * @param  array<string, Percentage|int|string|bool|null>  $details
     */
    private function overCompletePeriods(
        TraderComparisonContext $context,
        ComparisonMetricKey $key,
        TraderPerformanceSeriesReport $report,
        Percentage|int|null $value,
        int $requiredPeriods,
        array $warnings,
        array $details = [],
    ): ComparisonMetric {
        $complete = $report->series->completePeriods();
        $observation = $complete === [] ? $context->noData : ObservationPeriod::ofPeriods($report->series->granularity, $complete);

        if ($value === null) {
            return ComparisonMetric::unavailable(
                $key,
                MetricUnavailableReason::InsufficientHistory,
                $observation,
                $warnings,
                [...$details, 'required_complete_periods' => $requiredPeriods, 'available_complete_periods' => count($complete)],
            );
        }

        return ComparisonMetric::available($key, $value, $observation, $warnings, $details);
    }

    private function maxDrawdown(ComparisonMetricKey $key, ReturnPeriodGranularity $granularity, ?TraderPerformanceSeriesReport $report, TraderComparisonContext $context): ComparisonMetric
    {
        if ($report === null) {
            return $this->noSeries($context, $granularity, [$key])[0];
        }

        $drawdown = $report->drawdown;
        $periods = $report->series->periods;
        $warnings = [...$this->seriesBoundaryWarnings($periods), ...$this->performanceWarnings($context)];

        if ($granularity === ReturnPeriodGranularity::Monthly) {
            $warnings[] = MetricWarning::MonthlyGranularityNotIntraday;
        }

        return ComparisonMetric::available(
            $key,
            $drawdown->maxDrawdown,
            ObservationPeriod::ofPeriods($granularity, $periods),
            $warnings,
            [
                'methodology_version' => $drawdown->methodologyVersion,
                'peak_period_start' => $drawdown->peakPeriodStart,
                'trough_period_start' => $drawdown->troughPeriodStart,
                'recovery_period_start' => $drawdown->recoveryPeriodStart,
            ],
        );
    }

    /**
     * @param  list<ComparisonMetricKey>  $keys
     * @return list<ComparisonMetric>
     */
    private function noSeries(TraderComparisonContext $context, ReturnPeriodGranularity $granularity, array $keys): array
    {
        $details = [
            'granularity' => $granularity->value,
            'performance_visibility' => $context->trader->performance_visibility?->value,
        ];

        return array_map(
            fn (ComparisonMetricKey $key): ComparisonMetric => ComparisonMetric::unavailable($key, MetricUnavailableReason::NoPerformanceData, $context->noData, [], $details),
            $keys,
        );
    }

    private function noSnapshot(ComparisonMetricKey $key, TraderComparisonContext $context): ComparisonMetric
    {
        return ComparisonMetric::unavailable($key, MetricUnavailableReason::NoStoredSnapshot, $context->noData, [], [
            'portfolio_visibility' => $context->trader->portfolio_visibility?->value,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function monthCounts(TraderPerformanceSeriesReport $report): array
    {
        return [
            'positive_months' => $report->consistency->positiveCount,
            'negative_months' => $report->consistency->negativeCount,
            'flat_months' => $report->consistency->flatCount,
        ];
    }

    private function exposureReason(?ExposureUnavailableReason $reason): MetricUnavailableReason
    {
        return match ($reason) {
            ExposureUnavailableReason::NoInvestedWeight => MetricUnavailableReason::NoInvestedWeight,
            default => MetricUnavailableReason::NoData,
        };
    }

    private function wholeSeries(?TraderPerformanceSeriesReport $report): ?ObservationPeriod
    {
        $periods = $report?->series->periods ?? [];

        return $report === null || $periods === [] ? null : ObservationPeriod::ofPeriods($report->series->granularity, $periods);
    }

    /**
     * A value guaranteed by a calculator contract (e.g. ConcentrationDimension
     * metrics are null exactly when Unavailable; a stored series is never
     * empty) — fails loudly instead of substituting 0.
     */
    private function required(?Percentage $value): Percentage
    {
        return $value ?? throw new LogicException('A calculator returned no value where its contract guarantees one.');
    }
}
