<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\Comparison\BuildTraderComparison;
use App\Application\Traders\Comparison\ComparisonDimension;
use App\Application\Traders\Comparison\ComparisonMetric;
use App\Application\Traders\Comparison\ComparisonMetricKey;
use App\Application\Traders\Comparison\MetricStatus;
use App\Application\Traders\Comparison\MetricUnavailableReason;
use App\Application\Traders\Comparison\MetricWarning;
use App\Application\Traders\Comparison\ObservationBasis;
use App\Application\Traders\Comparison\ObservationPeriod;
use App\Application\Traders\Comparison\TraderComparison;
use App\Application\Traders\Comparison\TraderComparisonEntry;
use App\Application\Traders\Comparison\TraderComparisonRejected;
use App\Application\Traders\Comparison\TraderComparisonRejectionReason;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use App\Models\PerformancePoint;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CopySimulationFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-07 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Stores a monthly series starting at `$firstMonth` (Y-m-01). The month of
 * 2026-10 is in progress at the sync time 2026-10-07 03:00.
 *
 * @param  list<int>  $returnsPpb
 */
function comparisonMonthlySeries(Trader $trader, string $firstMonth, array $returnsPpb, string $syncedAt = '2026-10-07 03:00:00'): void
{
    foreach ($returnsPpb as $index => $ppb) {
        PerformancePoint::factory()->for($trader)->create([
            'granularity' => ReturnPeriodGranularity::Monthly,
            'period_start' => Carbon::parse($firstMonth)->addMonthsNoOverflow($index)->format('Y-m-d'),
            'gain_ppb' => $ppb,
            'synced_at' => $syncedAt,
        ]);
    }
}

/**
 * @param  list<int>  $returnsPpb
 */
function comparisonDailySeries(Trader $trader, string $firstDay, array $returnsPpb): void
{
    foreach ($returnsPpb as $index => $ppb) {
        PerformancePoint::factory()->for($trader)->create([
            'granularity' => ReturnPeriodGranularity::Daily,
            'period_start' => Carbon::parse($firstDay)->addDays($index)->format('Y-m-d'),
            'gain_ppb' => $ppb,
            'synced_at' => '2026-10-07 03:00:00',
        ]);
    }
}

/**
 * Trader A — 26 monthly points 2024-09 … 2026-10 (25 complete + 2026-10 in
 * progress), all 0% except: 2024-09 +20%, 2025-05 −10%, 2026-09 +10%,
 * 2026-10 (in progress) +5%. Daily: 2026-10-04 … 06 = +10%, −20%, +5%.
 * Snapshot: the copy simulator fixture (CopySimulationFixtures.php).
 */
function comparisonTraderA(): Trader
{
    $trader = Trader::factory()->create([
        'username' => 'alpha',
        'profile_synced_at' => '2026-10-07 02:00:00',
        'performance_synced_at' => '2026-10-07 03:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
        'portfolio_synced_at' => '2026-10-07 01:00:00',
        'portfolio_visibility' => PerformanceVisibility::Available,
    ]);

    $returns = array_fill(0, 26, 0);
    $returns[0] = 200_000_000;   // 2024-09
    $returns[8] = -100_000_000;  // 2025-05
    $returns[24] = 100_000_000;  // 2026-09
    $returns[25] = 50_000_000;   // 2026-10, in progress
    comparisonMonthlySeries($trader, '2024-09-01', $returns);
    comparisonDailySeries($trader, '2026-10-04', [100_000_000, -200_000_000, 50_000_000]);

    copySimulationSnapshot($trader, ['captured_at' => '2026-10-07 01:00:00', 'last_confirmed_at' => '2026-10-07 01:00:00']);

    return $trader;
}

/**
 * Trader B — 13 monthly points 2025-10 … 2026-10 (12 complete + in
 * progress), every month +1%. No daily series, no snapshot.
 */
function comparisonTraderB(): Trader
{
    $trader = Trader::factory()->create([
        'username' => 'bravo',
        'performance_synced_at' => '2026-10-07 03:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
    ]);

    comparisonMonthlySeries($trader, '2025-10-01', array_fill(0, 13, 10_000_000));

    return $trader;
}

function ppbOf(ComparisonMetric $metric): int
{
    expect($metric->value)->toBeInstanceOf(Percentage::class);

    return $metric->value->partsPerBillion();
}

it('compares two traders with every §14 metric as an independent value', function () {
    $a = comparisonTraderA();
    $b = comparisonTraderB();

    $comparison = app(BuildTraderComparison::class)->handle([$a->id, $b->id]);
    $alpha = $comparison->entry($a->id);

    expect($comparison->methodologyVersion)->toBe('comparison-v1')
        ->and($comparison->staleAfterHours)->toBe(48)
        ->and($comparison->failedRunWindowDays)->toBe(7)
        ->and(array_map(fn (TraderComparisonEntry $entry): string => $entry->username, $comparison->entries))->toBe(['alpha', 'bravo'])
        ->and(array_keys($alpha->metrics))->toBe(array_map(fn (ComparisonMetricKey $key): string => $key->value, ComparisonMetricKey::cases()));

    // Performance. All 26 periods: 1.2 × 0.9 × 1.1 × 1.05 − 1 = 0.2474
    $cumulative = $alpha->metric(ComparisonMetricKey::CumulativeReturn);
    expect(ppbOf($cumulative))->toBe(247_400_000)
        ->and($cumulative->status)->toBe(MetricStatus::Available)
        ->and($cumulative->warnings)->toBe([MetricWarning::IncludesInProgressPeriod])
        ->and($cumulative->observation->basis)->toBe(ObservationBasis::ReturnSeries)
        ->and($cumulative->observation->from->format('Y-m-d'))->toBe('2024-09-01')
        ->and($cumulative->observation->to->format('Y-m-d'))->toBe('2026-10-01')
        ->and($cumulative->observation->pointCount)->toBe(26)
        ->and($cumulative->observation->includesInProgress)->toBeTrue();

    // Trailing 12 = 2025-10 … 2026-09: only +10% → 0.10
    $trailing12 = $alpha->metric(ComparisonMetricKey::Trailing12MonthReturn);
    expect(ppbOf($trailing12))->toBe(100_000_000)
        ->and($trailing12->observation->from->format('Y-m-d'))->toBe('2025-10-01')
        ->and($trailing12->observation->to->format('Y-m-d'))->toBe('2026-09-01')
        ->and($trailing12->observation->pointCount)->toBe(12)
        ->and($trailing12->observation->includesInProgress)->toBeFalse();

    // Trailing 24 = 2024-10 … 2026-09: 0.9 × 1.1 − 1 = −0.01
    // Average of 25 complete: (0.2 − 0.1 + 0.1) / 25 = 0.008; median 0; 2 of 25 positive = 0.08
    expect(ppbOf($alpha->metric(ComparisonMetricKey::Trailing24MonthReturn)))->toBe(-10_000_000)
        ->and($alpha->metric(ComparisonMetricKey::Trailing24MonthReturn)->observation->from->format('Y-m-d'))->toBe('2024-10-01')
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::AverageMonthlyReturn)))->toBe(8_000_000)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::MedianMonthlyReturn)))->toBe(0)
        ->and($alpha->metric(ComparisonMetricKey::MedianMonthlyReturn)->status)->toBe(MetricStatus::Available)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::ProfitableMonthRatio)))->toBe(80_000_000)
        ->and($alpha->metric(ComparisonMetricKey::ProfitableMonthRatio)->details)->toBe(['positive_months' => 2, 'negative_months' => 1, 'flat_months' => 22])
        ->and($alpha->metric(ComparisonMetricKey::AverageMonthlyReturn)->observation->pointCount)->toBe(25);

    // Risk. Daily equity 1.1, 0.88, 0.924 → max drawdown 0.88 / 1.1 − 1 = −0.2
    // Monthly equity peaks at 1.2 (2024-09), 1.08 in 2025-05 → 0.1
    $daily = $alpha->metric(ComparisonMetricKey::DailyMaxDrawdown);
    $monthly = $alpha->metric(ComparisonMetricKey::MonthlyMaxDrawdown);
    expect(ppbOf($daily))->toBe(200_000_000)
        ->and($daily->observation->granularity)->toBe(ReturnPeriodGranularity::Daily)
        ->and($daily->observation->pointCount)->toBe(3)
        ->and($daily->warnings)->not->toContain(MetricWarning::MonthlyGranularityNotIntraday)
        ->and(ppbOf($monthly))->toBe(100_000_000)
        ->and($monthly->warnings)->toContain(MetricWarning::MonthlyGranularityNotIntraday)
        ->and($alpha->metric(ComparisonMetricKey::MonthlyVolatility)->status)->toBe(MetricStatus::Available)
        ->and($alpha->metric(ComparisonMetricKey::AnnualizedVolatility)->details['annualized'])->toBeTrue();

    $risk = $alpha->metric(ComparisonMetricKey::RiskScore);
    expect($risk->status)->toBe(MetricStatus::Unavailable)
        ->and($risk->value)->toBeNull()
        ->and($risk->unavailableReason)->toBe(MetricUnavailableReason::NotProvidedBySource);

    // Concentration (invested-only, P = 992.5M): largest 500 / 992.5 = 0.503778337… ;
    // top 3 = 900 / 992.5 = 0.906801007… ; every position 1x → weighted leverage 1
    $largest = $alpha->metric(ComparisonMetricKey::LargestPosition);
    expect(ppbOf($largest))->toBe(503_778_338)
        ->and($largest->details['instrument_id'])->toBe('1001')
        ->and($largest->observation->basis)->toBe(ObservationBasis::PortfolioSnapshot)
        ->and($largest->observation->from->format('Y-m-d H:i:s'))->toBe('2026-10-07 01:00:00')
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::TopThreeConcentration)))->toBe(906_801_008)
        ->and($alpha->metric(ComparisonMetricKey::WeightedLeverage)->value)->toBe('1.000000000')
        ->and($alpha->metric(ComparisonMetricKey::WeightedLeverage)->status)->toBe(MetricStatus::Available);

    // Consistency. Complete months sorted: −0.1, 0 × 22, 0.1, 0.2 → IQR 0 (Q1 = Q3 = 0)
    // Complete cumulative 1.2 × 0.9 × 1.1 − 1 = 0.188; without +20%: −0.01;
    // contribution 0.198; share 0.198 / 0.188 = 1.053191489… ; without best 3 (0.2, 0.1, 0): −0.1
    expect(ppbOf($alpha->metric(ComparisonMetricKey::PositiveMonthRatio)))->toBe(80_000_000)
        ->and($alpha->metric(ComparisonMetricKey::LongestLosingStreak)->value)->toBe(1)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::MonthlyReturnDispersion)))->toBe(0)
        ->and($alpha->metric(ComparisonMetricKey::MonthlyReturnDispersion)->details['measure'])->toBe('interquartile_range')
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::BestMonthDependency)))->toBe(1_053_191_489)
        ->and($alpha->metric(ComparisonMetricKey::BestMonthDependency)->details['best_month_contribution']->partsPerBillion())->toBe(198_000_000)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::ReturnExcludingBestMonth)))->toBe(-10_000_000)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::ReturnExcludingBestThreeMonths)))->toBe(-100_000_000);

    // Copyability (see BuildCopySimulationMatrixTest for the hand calculation).
    expect(ppbOf($alpha->metric(ComparisonMetricKey::CoverageAt200)))->toBe(987_405_541)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::CoverageAt500)))->toBe(998_992_443)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::CoverageAt1000)))->toBe(1_000_000_000)
        ->and($alpha->metric(ComparisonMetricKey::SkippedCountAt200)->value)->toBe(5)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::SkippedWeightAt200)))->toBe(12_500_000)
        ->and($alpha->metric(ComparisonMetricKey::SkippedCountAt500)->value)->toBe(2)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::SkippedWeightAt500)))->toBe(1_000_000)
        ->and($alpha->metric(ComparisonMetricKey::SkippedCountAt1000)->value)->toBe(1)
        ->and(ppbOf($alpha->metric(ComparisonMetricKey::SkippedWeightAt1000)))->toBe(0)
        ->and($alpha->metric(ComparisonMetricKey::MinimumFor90)->value)->toEqual(Money::fromCents(20_000))
        ->and($alpha->metric(ComparisonMetricKey::MinimumFor95)->value)->toEqual(Money::fromCents(20_000))
        ->and($alpha->metric(ComparisonMetricKey::MinimumFor99)->value)->toEqual(Money::fromCents(22_223))
        ->and($alpha->metric(ComparisonMetricKey::MinimumForAllVisible)->value)->toEqual(Money::fromCents(100_000))
        ->and($alpha->metric(ComparisonMetricKey::MinimumForAllVisible)->details['informational'])->toBeTrue()
        ->and($alpha->metric(ComparisonMetricKey::CoverageAt200)->warnings)->toBe([]);

    // Data quality: everything fresh; profile + 25 complete months + daily + snapshot = 4 / 4
    // collectable = 100%; the 4 sources the application does not collect are listed apart.
    $completeness = $alpha->metric(ComparisonMetricKey::CompletenessScore);
    expect($alpha->metric(ComparisonMetricKey::StaleDataWarning)->value)->toBeFalse()
        ->and(ppbOf($completeness))->toBe(1_000_000_000)
        ->and($completeness->observation->basis)->toBe(ObservationBasis::EvaluatedAt)
        ->and($completeness->details)->toBe([
            'methodology_version' => 'completeness-v1',
            'formula' => 'present_count / collectable_count',
            'present_count' => 4,
            'collectable_count' => 4,
            'total_count' => 8,
            'collectable_checks' => [
                'profile' => 'present',
                'monthly_history_24' => 'present',
                'daily_data' => 'present',
                'live_portfolio' => 'present',
            ],
            'not_supported_checks' => [
                'asset_history' => 'not_collected_by_application',
                'exposure_history' => 'not_collected_by_application',
                'trade_info' => 'not_collected_by_application',
                'copier_history' => 'not_collected_by_application',
            ],
        ])
        ->and($alpha->metric(ComparisonMetricKey::PerformanceLastSuccessfulSync)->value->format('Y-m-d H:i:s'))->toBe('2026-10-07 03:00:00')
        ->and($alpha->metric(ComparisonMetricKey::PortfolioVisibility)->value)->toBe('available')
        ->and($alpha->metric(ComparisonMetricKey::FailedEndpointCount)->value)->toBe(0);
});

it('marks unavailable metrics with a reason and the history they would need — never a silent zero', function () {
    $comparison = app(BuildTraderComparison::class)->handle([comparisonTraderA()->id, ($b = comparisonTraderB())->id]);
    $bravo = $comparison->entry($b->id);

    // 12 complete months of +1%: 1.01¹² − 1 = 0.126825030… → 126_825_030 ppb
    $trailing24 = $bravo->metric(ComparisonMetricKey::Trailing24MonthReturn);
    expect(ppbOf($bravo->metric(ComparisonMetricKey::Trailing12MonthReturn)))->toBe(126_825_030)
        ->and($trailing24->status)->toBe(MetricStatus::Unavailable)
        ->and($trailing24->value)->toBeNull()
        ->and($trailing24->unavailableReason)->toBe(MetricUnavailableReason::InsufficientHistory)
        ->and($trailing24->details)->toBe(['required_complete_periods' => 24, 'available_complete_periods' => 12])
        ->and($trailing24->observation->pointCount)->toBe(12)
        // every month +1% → no losing month, IQR 0
        ->and($bravo->metric(ComparisonMetricKey::LongestLosingStreak)->value)->toBe(0)
        ->and($bravo->metric(ComparisonMetricKey::DailyMaxDrawdown)->unavailableReason)->toBe(MetricUnavailableReason::NoPerformanceData)
        ->and($bravo->metric(ComparisonMetricKey::DailyMaxDrawdown)->details['granularity'])->toBe('daily');

    foreach ($bravo->dimension(ComparisonDimension::Copyability) as $metric) {
        expect($metric->status)->toBe(MetricStatus::Unavailable)
            ->and($metric->value)->toBeNull()
            ->and($metric->unavailableReason)->toBe(MetricUnavailableReason::NoStoredSnapshot);
    }

    foreach ([ComparisonMetricKey::LargestPosition, ComparisonMetricKey::TopThreeConcentration, ComparisonMetricKey::WeightedLeverage] as $key) {
        expect($bravo->metric($key)->unavailableReason)->toBe(MetricUnavailableReason::NoStoredSnapshot);
    }

    expect($bravo->dimension(ComparisonDimension::Copyability))->toHaveCount(13)
        ->and($bravo->metric(ComparisonMetricKey::PortfolioLastSuccessfulSync)->unavailableReason)->toBe(MetricUnavailableReason::NeverSynced)
        ->and($bravo->metric(ComparisonMetricKey::PortfolioVisibility)->unavailableReason)->toBe(MetricUnavailableReason::NeverSynced)
        ->and($bravo->metric(ComparisonMetricKey::StaleDataWarning)->value)->toBeTrue()
        ->and($bravo->metric(ComparisonMetricKey::StaleDataWarning)->details['portfolio_freshness'])->toBe('never_synced')
        // no profile, < 24 complete months, no daily, no snapshot → 0 / 4 collectable
        ->and(ppbOf($bravo->metric(ComparisonMetricKey::CompletenessScore)))->toBe(0)
        ->and($bravo->metric(ComparisonMetricKey::CompletenessScore)->details['collectable_checks'])->toBe([
            'profile' => 'missing',
            'monthly_history_24' => 'missing',
            'daily_data' => 'missing',
            'live_portfolio' => 'missing',
        ])
        ->and($bravo->metric(ComparisonMetricKey::CompletenessScore)->details['collectable_count'])->toBe(4);
});

it('shows a trader without performance data as unavailable in every series metric', function () {
    $empty = Trader::factory()->create(['performance_visibility' => PerformanceVisibility::Private]);

    $entry = app(BuildTraderComparison::class)->handle([comparisonTraderA()->id, $empty->id])->entry($empty->id);

    foreach ([ComparisonDimension::Performance, ComparisonDimension::Consistency] as $dimension) {
        foreach ($entry->dimension($dimension) as $metric) {
            expect($metric->status)->toBe(MetricStatus::Unavailable)
                ->and($metric->value)->toBeNull()
                ->and($metric->unavailableReason)->toBe(MetricUnavailableReason::NoPerformanceData)
                ->and($metric->details['performance_visibility'])->toBe('private');
        }
    }

    expect($entry->monthlyObservation)->toBeNull()
        ->and($entry->metric(ComparisonMetricKey::MonthlyMaxDrawdown)->unavailableReason)->toBe(MetricUnavailableReason::NoPerformanceData)
        ->and($entry->metric(ComparisonMetricKey::MonthlyVolatility)->unavailableReason)->toBe(MetricUnavailableReason::NoPerformanceData)
        ->and($entry->metric(ComparisonMetricKey::PerformanceLastSuccessfulSync)->unavailableReason)->toBe(MetricUnavailableReason::NeverSynced)
        ->and($entry->metric(ComparisonMetricKey::StaleDataWarning)->details['performance_freshness'])->toBe('no_longer_visible');
});

it('shows a private portfolio with its last known snapshot, clearly marked stale (D-045)', function () {
    $private = Trader::factory()->create([
        'portfolio_synced_at' => '2026-10-07 01:00:00',
        'portfolio_visibility' => PerformanceVisibility::Private,
    ]);
    copySimulationSnapshot($private, ['captured_at' => '2026-09-01 10:00:00', 'last_confirmed_at' => '2026-09-02 10:00:00']);

    $entry = app(BuildTraderComparison::class)->handle([comparisonTraderA()->id, $private->id])->entry($private->id);

    foreach ($entry->dimension(ComparisonDimension::Copyability) as $metric) {
        expect($metric->status)->toBe(MetricStatus::Available)
            ->and($metric->warnings)->toBe([MetricWarning::SnapshotNoLongerVisible])
            ->and($metric->observation->from->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:00:00')
            ->and($metric->observation->to->format('Y-m-d H:i:s'))->toBe('2026-09-02 10:00:00');
    }

    expect(ppbOf($entry->metric(ComparisonMetricKey::CoverageAt200)))->toBe(987_405_541)
        ->and($entry->metric(ComparisonMetricKey::LargestPosition)->warnings)->toBe([MetricWarning::SnapshotNoLongerVisible])
        ->and($entry->metric(ComparisonMetricKey::StaleDataWarning)->value)->toBeTrue()
        ->and($entry->metric(ComparisonMetricKey::StaleDataWarning)->details['portfolio_freshness'])->toBe('no_longer_visible')
        ->and($entry->metric(ComparisonMetricKey::CompletenessScore)->details['collectable_checks']['live_portfolio'])->toBe('stale')
        ->and($entry->metric(ComparisonMetricKey::PortfolioVisibility)->value)->toBe('private');
});

it('flags data as stale strictly after 48 hours since the last successful sync', function () {
    $atBoundary = Trader::factory()->create([
        'portfolio_synced_at' => '2026-10-05 12:00:00',
        'portfolio_visibility' => PerformanceVisibility::Available,
        'performance_synced_at' => '2026-10-05 12:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
    ]);
    $pastBoundary = Trader::factory()->create([
        'portfolio_synced_at' => '2026-10-05 11:59:59',
        'portfolio_visibility' => PerformanceVisibility::Available,
        'performance_synced_at' => '2026-10-05 11:59:59',
        'performance_visibility' => PerformanceVisibility::Available,
    ]);
    copySimulationSnapshot($pastBoundary, ['captured_at' => '2026-10-05 11:59:59', 'last_confirmed_at' => '2026-10-05 11:59:59']);
    comparisonMonthlySeries($pastBoundary, '2026-08-01', [10_000_000, 20_000_000], '2026-10-05 11:59:59');

    $comparison = app(BuildTraderComparison::class)->handle([$atBoundary->id, $pastBoundary->id]);
    $fresh = $comparison->entry($atBoundary->id);
    $stale = $comparison->entry($pastBoundary->id);

    expect($fresh->metric(ComparisonMetricKey::StaleDataWarning)->value)->toBeFalse()
        ->and($fresh->metric(ComparisonMetricKey::PortfolioLastSuccessfulSync)->details['freshness'])->toBe('fresh')
        ->and($stale->metric(ComparisonMetricKey::StaleDataWarning)->value)->toBeTrue()
        ->and($stale->metric(ComparisonMetricKey::StaleDataWarning)->details)->toMatchArray(['performance_freshness' => 'stale', 'portfolio_freshness' => 'stale'])
        ->and($stale->metric(ComparisonMetricKey::CoverageAt500)->warnings)->toBe([MetricWarning::SnapshotStale])
        ->and($stale->metric(ComparisonMetricKey::CumulativeReturn)->warnings)->toContain(MetricWarning::PerformanceStale);
});

it('shows the common period and flags differing observation periods', function () {
    $a = comparisonTraderA();
    $b = comparisonTraderB();

    $periods = app(BuildTraderComparison::class)->handle([$a->id, $b->id])->periods;

    expect($periods->observationPeriodsDiffer())->toBeTrue()
        ->and($periods->monthly->differs)->toBeTrue()
        ->and($periods->monthly->commonFrom->format('Y-m-d'))->toBe('2025-10-01')
        ->and($periods->monthly->commonTo->format('Y-m-d'))->toBe('2026-10-01')
        ->and($periods->monthly->observations[$a->id]->pointCount)->toBe(26)
        ->and($periods->monthly->observations[$b->id]->pointCount)->toBe(13)
        ->and($periods->monthly->observations[$b->id]->from->format('Y-m-d'))->toBe('2025-10-01')
        ->and($periods->monthly->tradersWithoutData)->toBe([])
        ->and($periods->daily->differs)->toBeTrue()
        ->and($periods->daily->tradersWithoutData)->toBe([$b->id])
        ->and($periods->daily->hasCommonPeriod())->toBeFalse()
        ->and($periods->snapshots->tradersWithoutSnapshot)->toBe([$b->id])
        ->and($periods->snapshots->oldestCapturedAt->format('Y-m-d H:i:s'))->toBe('2026-10-07 01:00:00');
});

it('does not flag identical observation periods', function () {
    $first = Trader::factory()->create();
    $second = Trader::factory()->create();
    comparisonMonthlySeries($first, '2026-01-01', [10_000_000, 20_000_000, 30_000_000]);
    comparisonMonthlySeries($second, '2026-01-01', [-10_000_000, 0, 50_000_000]);

    $periods = app(BuildTraderComparison::class)->handle([$first->id, $second->id])->periods;

    expect($periods->monthly->differs)->toBeFalse()
        ->and($periods->monthly->commonFrom->format('Y-m-d'))->toBe('2026-01-01')
        ->and($periods->monthly->commonTo->format('Y-m-d'))->toBe('2026-03-01')
        ->and($periods->daily->differs)->toBeFalse()
        ->and($periods->snapshots->differs())->toBeFalse()
        ->and($periods->observationPeriodsDiffer())->toBeFalse();
});

it('compares ten traders in the requested order', function () {
    $traders = Trader::factory()->count(10)->create();
    $ids = array_reverse($traders->modelKeys());

    $comparison = app(BuildTraderComparison::class)->handle($ids);

    expect(array_map(fn (TraderComparisonEntry $entry): int => $entry->traderId, $comparison->entries))->toBe($ids)
        ->and($comparison->periods->monthly->tradersWithoutData)->toBe($ids);
});

it('rejects fewer than two, more than ten, duplicate, and unknown traders', function (Closure $ids, TraderComparisonRejectionReason $reason, Closure $offending) {
    $traders = Trader::factory()->count(11)->create()->modelKeys();

    try {
        app(BuildTraderComparison::class)->handle($ids($traders));
        $this->fail('Expected the comparison to be rejected.');
    } catch (TraderComparisonRejected $rejected) {
        expect($rejected->reason)->toBe($reason)
            ->and($rejected->traderIds)->toBe($offending($traders));
    }
})->with([
    'none' => [fn (array $ids): array => [], TraderComparisonRejectionReason::TooFewTraders, fn (): array => []],
    'one' => [fn (array $ids): array => [$ids[0]], TraderComparisonRejectionReason::TooFewTraders, fn (): array => []],
    'eleven' => [fn (array $ids): array => $ids, TraderComparisonRejectionReason::TooManyTraders, fn (): array => []],
    'duplicate' => [fn (array $ids): array => [$ids[0], $ids[1], $ids[0]], TraderComparisonRejectionReason::DuplicateTrader, fn (array $ids): array => [$ids[0]]],
    'unknown' => [fn (array $ids): array => [$ids[0], 999_999], TraderComparisonRejectionReason::UnknownTrader, fn (): array => [999_999]],
]);

it('counts failed performance and portfolio import runs of the trader in the last seven days', function () {
    $trader = Trader::factory()->create();
    $other = Trader::factory()->create();

    $run = fn (string $type, ImportRunStatus $status, string $startedAt, int $traderId) => ImportRun::factory()->create([
        'type' => $type,
        'status' => $status,
        'started_at' => $startedAt,
        'metadata' => ['query' => ['trader_id' => $traderId]],
    ]);

    $run('performance', ImportRunStatus::Failed, '2026-10-06 03:00:00', $trader->id);
    $run('performance', ImportRunStatus::Completed, '2026-10-06 03:00:00', $trader->id);
    $run('portfolio', ImportRunStatus::Failed, '2026-09-30 12:00:00', $trader->id);   // exactly 7 days → inside
    $run('portfolio', ImportRunStatus::Partial, '2026-10-01 12:00:00', $trader->id);
    $run('portfolio', ImportRunStatus::Failed, '2026-09-30 11:59:59', $trader->id);   // outside the window
    $run('performance', ImportRunStatus::Failed, '2026-10-06 03:00:00', $other->id);  // other trader
    $run('rankings', ImportRunStatus::Failed, '2026-10-06 03:00:00', $trader->id);    // other run type

    $metric = app(BuildTraderComparison::class)->handle([$trader->id, $other->id])->entry($trader->id)->metric(ComparisonMetricKey::FailedEndpointCount);

    expect($metric->value)->toBe(2)
        ->and($metric->details)->toBe([
            'window_days' => 7,
            'failed_performance_runs' => 1,
            'failed_portfolio_runs' => 1,
            'partial_runs' => 1,
            'runs_in_window' => 4,
        ])
        ->and($metric->observation->basis)->toBe(ObservationBasis::ImportRunWindow)
        ->and($metric->observation->from->format('Y-m-d H:i:s'))->toBe('2026-09-30 12:00:00')
        ->and($metric->observation->to->format('Y-m-d H:i:s'))->toBe('2026-10-07 12:00:00');
});

it('reports weighted leverage as not determinable when part of the invested weight has unknown leverage (D-041)', function () {
    $trader = Trader::factory()->create();
    $snapshot = copySimulationStoredSnapshot([['p1', '1', 500_000_000], ['p2', '2', 500_000_000]], [], $trader);
    $snapshot->positions()->where('external_position_id', 'p2')->update(['leverage' => null]);

    $metric = app(BuildTraderComparison::class)->handle([$trader->id, comparisonTraderB()->id])->entry($trader->id)->metric(ComparisonMetricKey::WeightedLeverage);

    expect($metric->status)->toBe(MetricStatus::Unavailable)
        ->and($metric->value)->toBeNull()
        ->and($metric->unavailableReason)->toBe(MetricUnavailableReason::LeverageNotDeterminable)
        ->and($metric->details['known_leverage_contribution'])->toBe('0.500000000')
        ->and($metric->details['unknown_leverage_weight']->partsPerBillion())->toBe(500_000_000);
});

it('makes no HTTP request and writes nothing', function () {
    $a = comparisonTraderA();
    $b = comparisonTraderB();
    $private = Trader::factory()->create(['portfolio_visibility' => PerformanceVisibility::Private]);
    copySimulationSnapshot($private);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $comparison = app(BuildTraderComparison::class)->handle([$a->id, $b->id, $private->id]);

    expect($comparison)->toBeInstanceOf(TraderComparison::class)
        ->and($writes)->toBe([]);
    Http::assertNothingSent();
});

it('has no overall or combined score anywhere in the read model', function () {
    foreach ([TraderComparison::class, TraderComparisonEntry::class] as $class) {
        $reflection = new ReflectionClass($class);
        $names = [
            ...array_map(fn (ReflectionProperty $property): string => $property->getName(), $reflection->getProperties()),
            ...array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()),
        ];

        expect(array_filter($names, fn (string $name): bool => preg_match('/score|overall|total|rank|winner/i', $name) === 1))->toBe([]);
    }

    // Completeness is a data-quality metric with its formula alongside, not a trader score.
    expect(ComparisonMetricKey::CompletenessScore->dimension())->toBe(ComparisonDimension::DataQuality);
});

it('scores completeness over the collectable checks only — a stale source lowers it, an unsupported one does not', function () {
    $trader = comparisonTraderA();
    $trader->update(['profile_synced_at' => '2026-10-05 12:00:00']);   // > 24 h → profile stale

    $completeness = app(BuildTraderComparison::class)->handle([$trader->id, comparisonTraderB()->id])
        ->entry($trader->id)->metric(ComparisonMetricKey::CompletenessScore);

    // 3 present / 4 collectable = 0.75
    expect(ppbOf($completeness))->toBe(750_000_000)
        ->and($completeness->details['collectable_checks']['profile'])->toBe('stale')
        ->and($completeness->details['present_count'])->toBe(3)
        ->and($completeness->details['not_supported_checks'])->toHaveCount(4);
});

it('gives every metric of every dimension an observation period, never null', function () {
    $full = comparisonTraderA();
    $seriesOnly = comparisonTraderB();
    $empty = Trader::factory()->create(['performance_visibility' => PerformanceVisibility::Private]);
    $privateSnapshot = Trader::factory()->create(['portfolio_visibility' => PerformanceVisibility::Private]);
    copySimulationSnapshot($privateSnapshot);
    $inProgressOnly = Trader::factory()->create(['performance_synced_at' => '2026-10-07 03:00:00']);
    comparisonMonthlySeries($inProgressOnly, '2026-10-01', [10_000_000]);

    $comparison = app(BuildTraderComparison::class)->handle([$full->id, $seriesOnly->id, $empty->id, $privateSnapshot->id, $inProgressOnly->id]);
    $checked = 0;

    foreach ($comparison->entries as $entry) {
        $metrics = array_merge(...array_map(fn (ComparisonDimension $dimension): array => $entry->dimension($dimension), ComparisonDimension::cases()));
        expect($metrics)->toHaveCount(count(ComparisonMetricKey::cases()));

        foreach ($metrics as $metric) {
            expect($metric->observation)->toBeInstanceOf(ObservationPeriod::class)
                ->and($metric->observation->to)->not->toBeNull();

            // The explicit empty period: nothing observed, evaluated at the comparison instant.
            if ($metric->observation->basis === ObservationBasis::NoData) {
                expect($metric->observation->from)->toBeNull()
                    ->and($metric->observation->pointCount)->toBe(0)
                    ->and($metric->observation->to)->toEqual($comparison->generatedAt)
                    ->and($metric->observation->hasData())->toBeFalse();
            }

            $checked++;
        }
    }

    $empty = $comparison->entry($empty->id);
    $inProgressOnly = $comparison->entry($inProgressOnly->id);
    $alpha = $comparison->entry($full->id);

    expect($checked)->toBe(5 * count(ComparisonMetricKey::cases()))
        ->and($empty->metric(ComparisonMetricKey::CumulativeReturn)->observation->basis)->toBe(ObservationBasis::NoData)
        ->and($empty->metric(ComparisonMetricKey::LargestPosition)->observation->basis)->toBe(ObservationBasis::NoData)
        ->and($empty->metric(ComparisonMetricKey::PerformanceLastSuccessfulSync)->observation->basis)->toBe(ObservationBasis::NoData)
        ->and($empty->metric(ComparisonMetricKey::PerformanceVisibility)->observation->basis)->toBe(ObservationBasis::NoData)
        // a series without a complete month: complete-period metrics have nothing to observe
        ->and($inProgressOnly->metric(ComparisonMetricKey::AverageMonthlyReturn)->observation->basis)->toBe(ObservationBasis::NoData)
        ->and($inProgressOnly->metric(ComparisonMetricKey::Trailing12MonthReturn)->observation->basis)->toBe(ObservationBasis::NoData)
        ->and($inProgressOnly->metric(ComparisonMetricKey::CumulativeReturn)->observation->basis)->toBe(ObservationBasis::ReturnSeries)
        // risk score: evaluated at the comparison instant; visibility: the sync that observed it
        ->and($alpha->metric(ComparisonMetricKey::RiskScore)->observation->basis)->toBe(ObservationBasis::EvaluatedAt)
        ->and($alpha->metric(ComparisonMetricKey::RiskScore)->observation->to)->toEqual($comparison->generatedAt)
        ->and($alpha->metric(ComparisonMetricKey::PerformanceVisibility)->observation->basis)->toBe(ObservationBasis::SyncRecord)
        ->and($alpha->metric(ComparisonMetricKey::PerformanceVisibility)->observation->to->format('Y-m-d H:i:s'))->toBe('2026-10-07 03:00:00')
        ->and($alpha->metric(ComparisonMetricKey::PortfolioVisibility)->observation->to->format('Y-m-d H:i:s'))->toBe('2026-10-07 01:00:00');
});

it('reads the data of ten traders in a bounded number of queries', function () {
    $ids = [];

    foreach (range(1, 10) as $index) {
        $trader = Trader::factory()->create([
            'performance_synced_at' => '2026-10-07 03:00:00',
            'performance_visibility' => PerformanceVisibility::Available,
            'portfolio_synced_at' => '2026-10-07 01:00:00',
            'portfolio_visibility' => PerformanceVisibility::Available,
        ]);
        comparisonMonthlySeries($trader, '2024-09-01', array_fill(0, 26, 10_000_000));
        comparisonDailySeries($trader, '2026-10-04', [10_000_000, -20_000_000, 5_000_000]);
        copySimulationSnapshot($trader)->positions()->update(['instrument_id' => Instrument::factory()->create()->id]);
        ImportRun::factory()->create([
            'type' => 'performance',
            'status' => ImportRunStatus::Failed,
            'started_at' => '2026-10-06 03:00:00',
            'metadata' => ['query' => ['trader_id' => $trader->id]],
        ]);
        $ids[] = $trader->id;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $comparison = app(BuildTraderComparison::class)->handle($ids);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    // 1 traders + 1 performance points (all traders, both granularities)
    // + 1 import runs (all traders) + 10 × 4 (snapshot count, latest
    // snapshot, its positions ONCE, their instruments) = 43. Before D-047
    // point 9 it was 101: per trader 2 point queries, 1 import-run query
    // and the positions read three times (+ instruments twice).
    $positionQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "portfolio_positions"'));

    expect($queries)->toHaveCount(43)
        ->and($positionQueries)->toHaveCount(10)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "performance_points"')))->toHaveCount(1)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "import_runs"')))->toHaveCount(1);

    foreach ($ids as $id) {
        expect($comparison->entry($id)->metric(ComparisonMetricKey::FailedEndpointCount)->value)->toBe(1)
            ->and(ppbOf($comparison->entry($id)->metric(ComparisonMetricKey::CoverageAt200)))->toBe(987_405_541)
            ->and($comparison->entry($id)->metric(ComparisonMetricKey::LargestPosition)->status)->toBe(MetricStatus::Available);
    }
});

/**
 * 13 monthly points 2025-09 … 2026-09, every month +1%, synced at `$syncedAt`.
 */
function comparisonMonthBoundaryTrader(string $username, string $syncedAt): Trader
{
    $trader = Trader::factory()->create([
        'username' => $username,
        'performance_synced_at' => $syncedAt,
        'performance_visibility' => PerformanceVisibility::Available,
    ]);
    comparisonMonthlySeries($trader, '2025-09-01', array_fill(0, 13, 10_000_000), $syncedAt);

    return $trader;
}

it('classifies the last month on both sides of the UTC month boundary', function () {
    Carbon::setTestNow('2026-10-01 00:00:00');
    // 23:59:59 UTC on the last day: September is still in progress.
    $before = comparisonMonthBoundaryTrader('before', '2026-09-30 23:59:59');
    // 00:00:00 UTC on the 1st: September is complete.
    $after = comparisonMonthBoundaryTrader('after', '2026-10-01 00:00:00');

    $comparison = app(BuildTraderComparison::class)->handle([$before->id, $after->id]);
    $beforeEntry = $comparison->entry($before->id);
    $afterEntry = $comparison->entry($after->id);

    // before: 12 complete (2025-09 … 2026-08); trailing 12 = 1.01¹² − 1
    $beforeTrailing = $beforeEntry->metric(ComparisonMetricKey::Trailing12MonthReturn);
    expect($beforeEntry->metric(ComparisonMetricKey::CumulativeReturn)->warnings)->toContain(MetricWarning::IncludesInProgressPeriod)
        ->and($beforeEntry->metric(ComparisonMetricKey::AverageMonthlyReturn)->observation->pointCount)->toBe(12)
        ->and($beforeTrailing->observation->from->format('Y-m-d'))->toBe('2025-09-01')
        ->and($beforeTrailing->observation->to->format('Y-m-d'))->toBe('2026-08-01')
        ->and(ppbOf($beforeTrailing))->toBe(126_825_030);

    // after: 13 complete; trailing 12 = 2025-10 … 2026-09
    $afterTrailing = $afterEntry->metric(ComparisonMetricKey::Trailing12MonthReturn);
    expect($afterEntry->metric(ComparisonMetricKey::CumulativeReturn)->warnings)->not->toContain(MetricWarning::IncludesInProgressPeriod)
        ->and($afterEntry->metric(ComparisonMetricKey::AverageMonthlyReturn)->observation->pointCount)->toBe(13)
        ->and($afterTrailing->observation->from->format('Y-m-d'))->toBe('2025-10-01')
        ->and($afterTrailing->observation->to->format('Y-m-d'))->toBe('2026-09-01')
        ->and($afterTrailing->observation->includesInProgress)->toBeFalse()
        ->and(ppbOf($afterTrailing))->toBe(126_825_030);
});

it('is not affected by the Europe/Malta display timezone (D-046)', function () {
    Carbon::setTestNow('2026-10-01 00:00:00');
    // 2026-09-30 23:59:59 UTC is already 2026-10-01 01:59:59 CEST in Malta:
    // a display-zone classification would wrongly close September.
    $before = comparisonMonthBoundaryTrader('before', '2026-09-30 23:59:59');
    $after = comparisonMonthBoundaryTrader('after', '2026-10-01 00:00:00');
    ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Failed,
        'started_at' => '2026-09-24 00:00:00',   // exactly 7 days before in UTC → inside
        'metadata' => ['query' => ['trader_id' => $before->id]],
    ]);

    $ids = [$before->id, $after->id];

    config(['app.display_timezone' => 'UTC']);
    $utc = app(BuildTraderComparison::class)->handle($ids);

    config(['app.display_timezone' => 'Europe/Malta']);
    $malta = app(BuildTraderComparison::class)->handle($ids, CarbonImmutable::parse('2026-10-01 02:00:00', 'Europe/Malta'));

    expect($malta->generatedAt)->toEqual($utc->generatedAt)
        ->and($malta->generatedAt->getTimezone()->getName())->toBe('UTC')
        ->and($malta->entry($before->id)->metric(ComparisonMetricKey::AverageMonthlyReturn)->observation->pointCount)->toBe(12)
        ->and($malta->entry($before->id)->metric(ComparisonMetricKey::Trailing12MonthReturn)->observation->to->format('Y-m-d'))->toBe('2026-08-01')
        ->and($malta->entry($before->id)->metric(ComparisonMetricKey::FailedEndpointCount)->value)->toBe(1)
        ->and($malta->entry($before->id)->metric(ComparisonMetricKey::FailedEndpointCount)->observation->from->format('Y-m-d H:i:s T'))->toBe('2026-09-24 00:00:00 UTC');

    foreach ($ids as $id) {
        foreach (ComparisonMetricKey::cases() as $key) {
            $expected = $utc->entry($id)->metric($key);
            $actual = $malta->entry($id)->metric($key);

            expect($actual->status)->toBe($expected->status)
                ->and($actual->value)->toEqual($expected->value)
                ->and($actual->unavailableReason)->toBe($expected->unavailableReason)
                ->and($actual->warnings)->toBe($expected->warnings)
                ->and($actual->observation)->toEqual($expected->observation);
        }
    }

    expect($malta->periods)->toEqual($utc->periods);
});
