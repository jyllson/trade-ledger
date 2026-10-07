<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\AnalysisProfiles\AnalysisProfileCriteria;
use App\Application\Traders\Comparison\ComparisonMetric;
use App\Application\Traders\Comparison\ComparisonMetricKey;
use App\Application\Traders\Comparison\CriterionOutcome;
use App\Application\Traders\Comparison\CriterionResult;
use App\Application\Traders\Comparison\CriterionUnknownReason;
use App\Application\Traders\Comparison\EvaluateProfileFilters;
use App\Application\Traders\Comparison\MetricUnavailableReason;
use App\Application\Traders\Comparison\MetricWarning;
use App\Application\Traders\Comparison\ObservationBasis;
use App\Application\Traders\Comparison\ObservationPeriod;
use App\Application\Traders\Comparison\ProfileCriterion;
use App\Application\Traders\Comparison\ProfileFilterResult;
use App\Application\Traders\Comparison\ProfileFilterVerdict;
use App\Application\Traders\Comparison\TraderComparisonEntry;

/**
 * Pure evaluator tests (D-048): entries are built in memory, every metric
 * unavailable (`no_data`) unless overridden.
 */
function filterNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-07 12:00:00 UTC');
}

/**
 * @param  array<string, ComparisonMetric>  $overrides  keyed by ComparisonMetricKey value
 */
function filterEntry(array $overrides = []): TraderComparisonEntry
{
    $metrics = [];

    foreach (ComparisonMetricKey::cases() as $key) {
        $metrics[$key->value] = $overrides[$key->value] ?? ComparisonMetric::unavailable($key, MetricUnavailableReason::NoData, ObservationPeriod::noData(filterNow()));
    }

    return new TraderComparisonEntry(1, 'alpha', null, null, null, null, null, null, $metrics);
}

function filterFraction(ComparisonMetricKey $key, int $ppb, bool $partial = false, array $details = [], array $warnings = []): ComparisonMetric
{
    return ComparisonMetric::available($key, Percentage::fromPartsPerBillion($ppb), ObservationPeriod::evaluatedAt(filterNow()), $warnings, $details, $partial);
}

function filterUnavailable(ComparisonMetricKey $key, MetricUnavailableReason $reason, array $details = []): ComparisonMetric
{
    return ComparisonMetric::unavailable($key, $reason, ObservationPeriod::noData(filterNow()), [], $details);
}

/**
 * Positive-month ratio over `$months` complete months.
 */
function filterPositiveMonths(int $months, int $ratioPpb): ComparisonMetric
{
    return ComparisonMetric::available(
        ComparisonMetricKey::PositiveMonthRatio,
        Percentage::fromPartsPerBillion($ratioPpb),
        new ObservationPeriod(ObservationBasis::ReturnSeries, ReturnPeriodGranularity::Monthly, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2025-12-01'), $months),
    );
}

/**
 * @param  array<string, mixed>  $criteria
 */
function filterProfile(array $criteria = []): AnalysisProfileCriteria
{
    return new AnalysisProfileCriteria(...[
        'profileId' => 7,
        'name' => 'Test',
        'budget' => Money::fromCents(50_000),
        'targetCoverage' => Percentage::fromPartsPerBillion(950_000_000),
        ...$criteria,
    ]);
}

function filterResult(AnalysisProfileCriteria $profile, TraderComparisonEntry $entry, ProfileCriterion $criterion): CriterionResult
{
    return (new EvaluateProfileFilters)->evaluate($profile, $entry)->result($criterion);
}

// --- not applied ---------------------------------------------------------

it('reports every null criterion as not applied, never as pass', function () {
    $result = (new EvaluateProfileFilters)->evaluate(filterProfile(), filterEntry());

    foreach ([
        ProfileCriterion::MaximumDrawdown,
        ProfileCriterion::MaximumRiskScore,
        ProfileCriterion::MaximumSinglePosition,
        ProfileCriterion::MinimumHistoryMonths,
        ProfileCriterion::MinimumPositiveMonths,
        ProfileCriterion::MaximumAllocationPerTrader,
    ] as $criterion) {
        expect($result->result($criterion)->outcome)->toBe(CriterionOutcome::NotApplied)
            ->and($result->result($criterion)->threshold)->toBeNull()
            ->and($result->result($criterion)->actual)->toBeNull();
    }

    // The target is always applied; with no snapshot data it is unknown.
    expect($result->result(ProfileCriterion::TargetCoverageAtBudget)->outcome)->toBe(CriterionOutcome::Unknown)
        ->and(array_keys($result->results))->toBe(array_map(fn (ProfileCriterion $criterion): string => $criterion->value, ProfileCriterion::cases()))
        ->and($result->profileId)->toBe(7);
});

// --- maximum drawdown (daily AND monthly) --------------------------------

it('passes the drawdown maximum exactly at the threshold and fails one ppb above', function () {
    $profile = filterProfile(['maximumDrawdown' => Percentage::fromPartsPerBillion(200_000_000)]);

    $atThreshold = filterResult($profile, filterEntry([
        'daily_max_drawdown' => filterFraction(ComparisonMetricKey::DailyMaxDrawdown, 200_000_000),
        'monthly_max_drawdown' => filterFraction(ComparisonMetricKey::MonthlyMaxDrawdown, 150_000_000),
    ]), ProfileCriterion::MaximumDrawdown);

    expect($atThreshold->outcome)->toBe(CriterionOutcome::Pass)
        ->and($atThreshold->actual)->toEqual(Percentage::fromPartsPerBillion(200_000_000))
        ->and($atThreshold->metricKeys)->toBe([ComparisonMetricKey::DailyMaxDrawdown, ComparisonMetricKey::MonthlyMaxDrawdown]);

    $above = filterResult($profile, filterEntry([
        'daily_max_drawdown' => filterFraction(ComparisonMetricKey::DailyMaxDrawdown, 100_000_000),
        'monthly_max_drawdown' => filterFraction(ComparisonMetricKey::MonthlyMaxDrawdown, 200_000_001),
    ]), ProfileCriterion::MaximumDrawdown);

    expect($above->outcome)->toBe(CriterionOutcome::Fail)
        ->and($above->actual)->toEqual(Percentage::fromPartsPerBillion(200_000_001))
        ->and($above->explanation)->toContain('monthly')
        ->and($above->explanation)->toContain('20.0000001%');
});

it('fails the drawdown on the one known granularity but never passes on it alone', function () {
    $profile = filterProfile(['maximumDrawdown' => Percentage::fromPartsPerBillion(200_000_000)]);
    $noDaily = filterUnavailable(ComparisonMetricKey::DailyMaxDrawdown, MetricUnavailableReason::NoPerformanceData);

    $fails = filterResult($profile, filterEntry([
        'daily_max_drawdown' => $noDaily,
        'monthly_max_drawdown' => filterFraction(ComparisonMetricKey::MonthlyMaxDrawdown, 300_000_000),
    ]), ProfileCriterion::MaximumDrawdown);

    $unknown = filterResult($profile, filterEntry([
        'daily_max_drawdown' => $noDaily,
        'monthly_max_drawdown' => filterFraction(ComparisonMetricKey::MonthlyMaxDrawdown, 100_000_000),
    ]), ProfileCriterion::MaximumDrawdown);

    $neither = filterResult($profile, filterEntry(), ProfileCriterion::MaximumDrawdown);

    expect($fails->outcome)->toBe(CriterionOutcome::Fail)
        ->and($unknown->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($unknown->unknownReason)->toBe(CriterionUnknownReason::MetricUnavailable)
        ->and($unknown->actual)->toEqual(Percentage::fromPartsPerBillion(100_000_000))
        ->and($unknown->explanation)->toContain('daily max drawdown is not available')
        ->and($neither->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($neither->actual)->toBeNull();
});

// --- risk score ----------------------------------------------------------

it('reports the risk score criterion as unknown because the source value is not collected', function () {
    $profile = filterProfile(['maximumRiskScore' => 6]);
    $result = filterResult($profile, filterEntry([
        'risk_score' => filterUnavailable(ComparisonMetricKey::RiskScore, MetricUnavailableReason::NotProvidedBySource),
    ]), ProfileCriterion::MaximumRiskScore);

    expect($result->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($result->threshold)->toBe(6)
        ->and($result->actual)->toBeNull()
        ->and($result->details['metric_unavailable_reason'])->toBe('not_provided_by_source')
        ->and($result->explanation)->toContain('neither pass nor fail');
});

it('compares an integer risk score inclusively when one is available', function (int $score, CriterionOutcome $expected) {
    $profile = filterProfile(['maximumRiskScore' => 6]);
    $metric = ComparisonMetric::available(ComparisonMetricKey::RiskScore, $score, ObservationPeriod::evaluatedAt(filterNow()));

    expect(filterResult($profile, filterEntry(['risk_score' => $metric]), ProfileCriterion::MaximumRiskScore)->outcome)->toBe($expected);
})->with([
    'below' => [5, CriterionOutcome::Pass],
    'at the maximum' => [6, CriterionOutcome::Pass],
    'above' => [7, CriterionOutcome::Fail],
]);

// --- maximum single position --------------------------------------------

it('evaluates the largest position against the maximum, inclusively', function (int $largestPpb, CriterionOutcome $expected) {
    $profile = filterProfile(['maximumSinglePosition' => Percentage::fromPartsPerBillion(250_000_000)]);
    $entry = filterEntry(['largest_position' => filterFraction(ComparisonMetricKey::LargestPosition, $largestPpb)]);

    expect(filterResult($profile, $entry, ProfileCriterion::MaximumSinglePosition)->outcome)->toBe($expected);
})->with([
    'below' => [249_999_999, CriterionOutcome::Pass],
    'exactly at' => [250_000_000, CriterionOutcome::Pass],
    'above' => [250_000_001, CriterionOutcome::Fail],
]);

it('treats a partial largest position as unknown, keeping its value and warnings', function () {
    $profile = filterProfile(['maximumSinglePosition' => Percentage::fromPartsPerBillion(250_000_000)]);
    $entry = filterEntry(['largest_position' => filterFraction(
        ComparisonMetricKey::LargestPosition,
        900_000_000,
        partial: true,
        warnings: [MetricWarning::PositionsWithoutUsableWeightExcluded],
    )]);

    $result = filterResult($profile, $entry, ProfileCriterion::MaximumSinglePosition);

    expect($result->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($result->unknownReason)->toBe(CriterionUnknownReason::MetricPartial)
        ->and($result->actual)->toEqual(Percentage::fromPartsPerBillion(900_000_000))
        ->and($result->warnings)->toBe([MetricWarning::PositionsWithoutUsableWeightExcluded]);
});

it('treats a missing snapshot as unknown for the single position', function () {
    $profile = filterProfile(['maximumSinglePosition' => Percentage::fromPartsPerBillion(250_000_000)]);
    $entry = filterEntry(['largest_position' => filterUnavailable(ComparisonMetricKey::LargestPosition, MetricUnavailableReason::NoStoredSnapshot)]);

    $result = filterResult($profile, $entry, ProfileCriterion::MaximumSinglePosition);

    expect($result->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($result->unknownReason)->toBe(CriterionUnknownReason::MetricUnavailable);
});

// --- minimum history -----------------------------------------------------

it('counts complete months of history against the minimum, inclusively', function (int $months, CriterionOutcome $expected) {
    $profile = filterProfile(['minimumHistoryMonths' => 12]);
    $result = filterResult($profile, filterEntry(['positive_month_ratio' => filterPositiveMonths($months, 500_000_000)]), ProfileCriterion::MinimumHistoryMonths);

    expect($result->outcome)->toBe($expected)
        ->and($result->actual)->toBe($months);
})->with([
    'one short' => [11, CriterionOutcome::Fail],
    'exactly' => [12, CriterionOutcome::Pass],
    'more' => [25, CriterionOutcome::Pass],
]);

it('knows a history of zero complete months but not a history without any series', function () {
    $profile = filterProfile(['minimumHistoryMonths' => 1]);

    $zero = filterResult($profile, filterEntry([
        'positive_month_ratio' => filterUnavailable(ComparisonMetricKey::PositiveMonthRatio, MetricUnavailableReason::InsufficientHistory, ['required_complete_periods' => 1, 'available_complete_periods' => 0]),
    ]), ProfileCriterion::MinimumHistoryMonths);

    $none = filterResult($profile, filterEntry([
        'positive_month_ratio' => filterUnavailable(ComparisonMetricKey::PositiveMonthRatio, MetricUnavailableReason::NoPerformanceData),
    ]), ProfileCriterion::MinimumHistoryMonths);

    expect($zero->outcome)->toBe(CriterionOutcome::Fail)
        ->and($zero->actual)->toBe(0)
        ->and($none->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($none->actual)->toBeNull()
        ->and($none->details['metric_unavailable_reason'])->toBe('no_performance_data');
});

// --- minimum positive months ---------------------------------------------

it('evaluates the positive-month ratio against the minimum, inclusively', function (int $ratioPpb, CriterionOutcome $expected) {
    $profile = filterProfile(['minimumPositiveMonths' => Percentage::fromPartsPerBillion(600_000_000)]);
    $result = filterResult($profile, filterEntry(['positive_month_ratio' => filterPositiveMonths(20, $ratioPpb)]), ProfileCriterion::MinimumPositiveMonths);

    expect($result->outcome)->toBe($expected);
})->with([
    'below' => [599_999_999, CriterionOutcome::Fail],
    'exactly at' => [600_000_000, CriterionOutcome::Pass],
    'above' => [600_000_001, CriterionOutcome::Pass],
]);

it('treats an unavailable positive-month ratio as unknown', function () {
    $profile = filterProfile(['minimumPositiveMonths' => Percentage::fromPartsPerBillion(600_000_000)]);
    $result = filterResult($profile, filterEntry([
        'positive_month_ratio' => filterUnavailable(ComparisonMetricKey::PositiveMonthRatio, MetricUnavailableReason::InsufficientHistory, ['available_complete_periods' => 0]),
    ]), ProfileCriterion::MinimumPositiveMonths);

    expect($result->outcome)->toBe(CriterionOutcome::Unknown);
});

// --- target coverage at budget -------------------------------------------

it('passes the target exactly at the coverage and fails one ppb below', function (int $coveragePpb, CriterionOutcome $expected) {
    $entry = filterEntry([
        'coverage_at_profile_budget' => filterFraction(ComparisonMetricKey::CoverageAtProfileBudget, $coveragePpb, details: ['copy_amount' => Money::fromCents(50_000)]),
        'minimum_for_profile_target' => ComparisonMetric::available(ComparisonMetricKey::MinimumForProfileTarget, Money::fromCents(33_334), ObservationPeriod::evaluatedAt(filterNow()), [], ['target_coverage' => Percentage::fromPartsPerBillion(950_000_000)]),
    ]);

    $result = filterResult(filterProfile(), $entry, ProfileCriterion::TargetCoverageAtBudget);

    expect($result->outcome)->toBe($expected)
        ->and($result->threshold)->toEqual(Percentage::fromPartsPerBillion(950_000_000))
        ->and($result->details['minimum_for_target'])->toEqual(Money::fromCents(33_334))
        ->and($result->explanation)->toContain('$500')
        ->and($result->explanation)->toContain('$333.34');
})->with([
    'below' => [949_999_999, CriterionOutcome::Fail],
    'exactly' => [950_000_000, CriterionOutcome::Pass],
    'above' => [1_000_000_000, CriterionOutcome::Pass],
]);

it('fails the target without positive weight in a complete snapshot but is unknown without a snapshot or for an estimate', function () {
    $noWeight = filterResult(filterProfile(), filterEntry([
        'coverage_at_profile_budget' => filterUnavailable(ComparisonMetricKey::CoverageAtProfileBudget, MetricUnavailableReason::NoPositiveWeight),
    ]), ProfileCriterion::TargetCoverageAtBudget);

    $noSnapshot = filterResult(filterProfile(), filterEntry([
        'coverage_at_profile_budget' => filterUnavailable(ComparisonMetricKey::CoverageAtProfileBudget, MetricUnavailableReason::NoStoredSnapshot),
    ]), ProfileCriterion::TargetCoverageAtBudget);

    $estimate = filterResult(filterProfile(), filterEntry([
        'coverage_at_profile_budget' => filterFraction(ComparisonMetricKey::CoverageAtProfileBudget, 990_000_000, partial: true, warnings: [MetricWarning::EstimatedFromIncompleteSnapshot]),
    ]), ProfileCriterion::TargetCoverageAtBudget);

    $noWeightEstimate = filterResult(filterProfile(), filterEntry([
        'coverage_at_profile_budget' => ComparisonMetric::unavailable(ComparisonMetricKey::CoverageAtProfileBudget, MetricUnavailableReason::NoPositiveWeight, ObservationPeriod::noData(filterNow()), [MetricWarning::EstimatedFromIncompleteSnapshot]),
    ]), ProfileCriterion::TargetCoverageAtBudget);

    expect($noWeight->outcome)->toBe(CriterionOutcome::Fail)
        ->and($noWeightEstimate->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($noWeightEstimate->unknownReason)->toBe(CriterionUnknownReason::MetricPartial)
        ->and($noWeightEstimate->details['metric_unavailable_reason'])->toBe('no_positive_weight')
        ->and($noWeight->actual)->toBeNull()
        ->and($noWeight->explanation)->toContain('unreachable')
        ->and($noSnapshot->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($noSnapshot->details['metric_unavailable_reason'])->toBe('no_stored_snapshot')
        ->and($estimate->outcome)->toBe(CriterionOutcome::Unknown)
        ->and($estimate->unknownReason)->toBe(CriterionUnknownReason::MetricPartial)
        ->and($estimate->warnings)->toBe([MetricWarning::EstimatedFromIncompleteSnapshot]);
});

it('refuses an entry built for another profile budget or target', function () {
    $entry = filterEntry([
        'coverage_at_profile_budget' => filterFraction(ComparisonMetricKey::CoverageAtProfileBudget, 990_000_000, details: ['copy_amount' => Money::fromCents(100_000)]),
    ]);

    (new EvaluateProfileFilters)->evaluate(filterProfile(), $entry);
})->throws(InvalidArgumentException::class, 'different profile budget or target');

// --- maximum allocation per trader ---------------------------------------

it('shows the maximum allocation per trader as informational, never evaluated', function () {
    $profile = filterProfile(['maximumAllocationPerTrader' => Percentage::fromPartsPerBillion(333_333_333)]);
    $result = filterResult($profile, filterEntry(), ProfileCriterion::MaximumAllocationPerTrader);

    // floor($500 × 0.333333333) = 16_666 cents
    expect($result->outcome)->toBe(CriterionOutcome::Informational)
        ->and($result->outcome->isApplied())->toBeFalse()
        ->and($result->actual)->toBeNull()
        ->and($result->details['allocation_amount'])->toEqual(Money::fromCents(16_666))
        ->and($result->explanation)->toContain('$166.66')
        ->and($result->explanation)->toContain('informational');
});

// --- derived verdict -----------------------------------------------------

it('derives the verdict by fixed precedence fail > unknown > pass, without weights', function () {
    $passingTarget = [
        'coverage_at_profile_budget' => filterFraction(ComparisonMetricKey::CoverageAtProfileBudget, 960_000_000),
    ];

    $allPass = (new EvaluateProfileFilters)->evaluate(
        filterProfile(['minimumHistoryMonths' => 12, 'maximumAllocationPerTrader' => Percentage::fromPartsPerBillion(500_000_000)]),
        filterEntry([...$passingTarget, 'positive_month_ratio' => filterPositiveMonths(12, 500_000_000)]),
    );

    $unknown = (new EvaluateProfileFilters)->evaluate(
        filterProfile(['maximumRiskScore' => 5]),
        filterEntry($passingTarget),
    );

    $failed = (new EvaluateProfileFilters)->evaluate(
        filterProfile(['maximumRiskScore' => 5, 'minimumHistoryMonths' => 24]),
        filterEntry([...$passingTarget, 'positive_month_ratio' => filterPositiveMonths(12, 500_000_000)]),
    );

    expect($allPass->verdict)->toBe(ProfileFilterVerdict::AllAppliedPassed)
        ->and($allPass->criteriaWithOutcome(CriterionOutcome::Pass))->toBe([ProfileCriterion::TargetCoverageAtBudget, ProfileCriterion::MinimumHistoryMonths])
        ->and($allPass->criteriaWithOutcome(CriterionOutcome::Informational))->toBe([ProfileCriterion::MaximumAllocationPerTrader])
        ->and($unknown->verdict)->toBe(ProfileFilterVerdict::AtLeastOneUnknown)
        ->and($failed->verdict)->toBe(ProfileFilterVerdict::AtLeastOneFailed)
        ->and($failed->criteriaWithOutcome(CriterionOutcome::Unknown))->toBe([ProfileCriterion::MaximumRiskScore])
        ->and($failed->criteriaWithOutcome(CriterionOutcome::Fail))->toBe([ProfileCriterion::MinimumHistoryMonths]);
});

it('has no score anywhere in the filter result', function () {
    foreach ([ProfileFilterResult::class, CriterionResult::class, EvaluateProfileFilters::class] as $class) {
        $reflection = new ReflectionClass($class);
        $names = [
            ...array_map(fn (ReflectionProperty $property): string => $property->getName(), $reflection->getProperties()),
            ...array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()),
        ];

        expect(array_filter($names, fn (string $name): bool => preg_match('/score|weight|overall|total|rank|points/i', $name) === 1))->toBe([]);
    }
});

it('rejects inconsistent criterion results', function () {
    new CriterionResult(ProfileCriterion::MaximumDrawdown, CriterionOutcome::Unknown, Percentage::zero(), null, 'x');
})->throws(InvalidArgumentException::class, 'unknown reason');
