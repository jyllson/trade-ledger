<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\Comparison\ComparisonMetric;
use App\Application\Traders\Comparison\ComparisonMetricKey;
use App\Application\Traders\Comparison\CriterionOutcome;
use App\Application\Traders\Comparison\CriterionResult;
use App\Application\Traders\Comparison\MetricStatus;
use App\Application\Traders\Comparison\MetricUnavailableReason;
use App\Application\Traders\Comparison\MetricUnit;
use App\Application\Traders\Comparison\MetricWarning;
use App\Application\Traders\Comparison\ObservationBasis;
use App\Application\Traders\Comparison\ObservationPeriod;
use App\Application\Traders\Comparison\ProfileCriterion;
use App\Models\PerformanceVisibility;
use DateTimeImmutable;

/**
 * Presentation of comparison metrics, observation periods and profile
 * criteria on the Compare traders page (docs/DECISIONS.md D-049). Exact
 * values come from the read model (D-047/D-048); this class only formats
 * them — percentages and money through BCMath helpers, instants in the
 * display timezone (D-046), calendar periods as stored UTC dates.
 */
final class ComparisonDisplay
{
    /**
     * @return array{text: string, status: string, reason: ?string, warnings: list<string>, period: string, show_period: bool}
     */
    public static function cell(ComparisonMetric $metric): array
    {
        return [
            'text' => $metric->status === MetricStatus::Unavailable ? 'Unavailable' : self::value($metric),
            'status' => $metric->status->value,
            'reason' => $metric->unavailableReason === null ? null : self::reason($metric),
            'warnings' => array_map(static fn (MetricWarning $warning): string => $warning->label(), $metric->warnings),
            'period' => self::period($metric->observation),
            // Snapshot capture times are listed once in the periods table at the
            // top (and in every cell's tooltip); series windows differ per metric.
            'show_period' => $metric->observation->basis === ObservationBasis::ReturnSeries,
        ];
    }

    public static function value(ComparisonMetric $metric): string
    {
        $value = $metric->value;

        return match (true) {
            $value === null => '—',
            $value instanceof Percentage => PercentageDisplay::format($value),
            $value instanceof Money => NumberDisplay::usd($value->cents()),
            $value instanceof DateTimeImmutable => DateTimeDisplay::format($value),
            is_bool($value) => $value ? 'Yes — some source is not fresh' : 'No',
            is_int($value) => self::count($metric->key, $value),
            $metric->key->unit() === MetricUnit::Multiple && is_numeric($value) => NumberDisplay::decimal($value).'x',
            $metric->key->unit() === MetricUnit::Visibility => self::visibility(PerformanceVisibility::tryFrom($value)),
            default => $value,
        };
    }

    public static function visibility(?PerformanceVisibility $visibility): string
    {
        return match ($visibility) {
            PerformanceVisibility::Available => 'Public',
            PerformanceVisibility::Private => 'Private',
            PerformanceVisibility::NotFound => 'Not found',
            null => 'Unknown',
        };
    }

    /**
     * Unavailable reason plus the supporting figures that explain it.
     */
    public static function reason(ComparisonMetric $metric): string
    {
        $reason = $metric->unavailableReason?->label() ?? '';
        $required = $metric->details['required_complete_periods'] ?? null;
        $available = $metric->details['available_complete_periods'] ?? null;

        if ($metric->unavailableReason === MetricUnavailableReason::InsufficientHistory && is_int($required) && is_int($available)) {
            return sprintf('%s: needs %d, has %d', $reason, $required, $available);
        }

        return $reason;
    }

    /**
     * Short text of an observation period; return-series bounds are period
     * starts (UTC calendar dates, D-046 point 3), other bounds instants.
     */
    public static function period(ObservationPeriod $observation): string
    {
        return match ($observation->basis) {
            ObservationBasis::ReturnSeries => self::series($observation),
            ObservationBasis::PortfolioSnapshot => sprintf('Snapshot captured %s, last confirmed %s', DateTimeDisplay::format($observation->from), DateTimeDisplay::format($observation->to)),
            ObservationBasis::ImportRunWindow => sprintf('Runs started %s – %s (%d in window)', DateTimeDisplay::format($observation->from), DateTimeDisplay::format($observation->to), $observation->pointCount),
            ObservationBasis::SyncRecord => 'Sync record '.DateTimeDisplay::format($observation->to),
            ObservationBasis::EvaluatedAt => 'Evaluated at '.DateTimeDisplay::format($observation->to),
            ObservationBasis::NoData => 'No stored data (evaluated '.DateTimeDisplay::format($observation->to).')',
        };
    }

    public static function series(?ObservationPeriod $observation): string
    {
        if ($observation === null || $observation->from === null || $observation->to === null) {
            return 'No stored series';
        }

        $monthly = $observation->granularity === ReturnPeriodGranularity::Monthly;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $flags = array_filter([
            $observation->includesPartialStart ? 'partial first' : null,
            $observation->includesInProgress ? 'last in progress' : null,
        ]);

        return sprintf(
            '%s → %s · %d %s%s',
            $observation->from->format($format),
            $observation->to->format($format),
            $observation->pointCount,
            $monthly ? ($observation->pointCount === 1 ? 'month' : 'months') : ($observation->pointCount === 1 ? 'day' : 'days'),
            $flags === [] ? '' : ' ('.implode(', ', $flags).')',
        );
    }

    /**
     * Short explanation shown under a metric label, for metrics whose
     * meaning is not obvious from the name.
     */
    public static function hint(ComparisonMetricKey $key): ?string
    {
        return match ($key) {
            ComparisonMetricKey::CumulativeReturn => 'Whole stored monthly series, compounded.',
            ComparisonMetricKey::Trailing12MonthReturn, ComparisonMetricKey::Trailing24MonthReturn => 'Last complete months only.',
            ComparisonMetricKey::MonthlyMaxDrawdown => 'Month-end values — not intraday.',
            ComparisonMetricKey::RiskScore => 'eToro\'s own 1–10 risk score; this application does not collect it.',
            ComparisonMetricKey::LargestPosition, ComparisonMetricKey::TopThreeConcentration, ComparisonMetricKey::WeightedLeverage => 'Latest stored snapshot, invested-only basis.',
            ComparisonMetricKey::MonthlyReturnDispersion => 'Interquartile range Q3 − Q1 of complete months.',
            ComparisonMetricKey::BestMonthDependency => 'Share of the compounded return contributed by the best month.',
            ComparisonMetricKey::CoverageAt200, ComparisonMetricKey::CoverageAt500, ComparisonMetricKey::CoverageAt1000, ComparisonMetricKey::CoverageAtProfileBudget => 'Share of the visible positive position weight that can be copied.',
            ComparisonMetricKey::StaleDataWarning => 'Any source older than 48 h, private / not found, or never synced.',
            ComparisonMetricKey::CompletenessScore => 'Describes the stored data, not the trader: present ÷ collectable checks, each weighted 1 (completeness-v1).',
            ComparisonMetricKey::FailedEndpointCount => 'Failed performance / portfolio sync runs in the last 7 days.',
            default => null,
        };
    }

    /**
     * "≥ 95%", "≤ 20%", "≥ 24 months" — the profile threshold of a criterion.
     */
    public static function threshold(ProfileCriterion $criterion, Percentage|Money|int|null $threshold): string
    {
        if ($threshold === null) {
            return 'Not applied';
        }

        $relation = match ($criterion) {
            ProfileCriterion::TargetCoverageAtBudget, ProfileCriterion::MinimumHistoryMonths, ProfileCriterion::MinimumPositiveMonths => '≥',
            ProfileCriterion::MaximumAllocationPerTrader => 'at most',
            default => '≤',
        };

        return $relation.' '.self::criterionValue($criterion, $threshold);
    }

    public static function criterionValue(ProfileCriterion $criterion, Percentage|Money|int|null $value): string
    {
        return match (true) {
            $value === null => '—',
            $value instanceof Percentage => self::exactPercent($value),
            $value instanceof Money => NumberDisplay::usd($value->cents()),
            $criterion === ProfileCriterion::MinimumHistoryMonths => $value.($value === 1 ? ' month' : ' months'),
            default => (string) $value,
        };
    }

    /**
     * Badge color of a criterion outcome — the outcome itself, never a
     * ranking colour.
     */
    public static function outcomeColor(CriterionOutcome $outcome): string
    {
        return match ($outcome) {
            CriterionOutcome::Pass => 'success',
            CriterionOutcome::Fail => 'danger',
            CriterionOutcome::Unknown => 'warning',
            CriterionOutcome::NotApplied => 'gray',
            CriterionOutcome::Informational => 'info',
        };
    }

    /**
     * @return array{outcome: string, color: string, actual: string, explanation: string, warnings: list<string>}
     */
    public static function criterion(CriterionResult $result): array
    {
        return [
            'outcome' => $result->outcome->label(),
            'color' => self::outcomeColor($result->outcome),
            'actual' => self::criterionValue($result->criterion, $result->actual),
            'explanation' => $result->explanation,
            'warnings' => array_map(static fn (MetricWarning $warning): string => $warning->label(), $result->warnings),
        ];
    }

    /**
     * Exact percent with trailing zeros trimmed ("95%", "12.5%") — a rounded
     * threshold could look equal to a failing value.
     */
    public static function exactPercent(Percentage $value): string
    {
        $percent = bcdiv((string) $value->partsPerBillion(), '10000000', 7);

        return rtrim(rtrim($percent, '0'), '.').'%';
    }

    private static function count(ComparisonMetricKey $key, int $value): string
    {
        $noun = match ($key) {
            ComparisonMetricKey::LongestLosingStreak => $value === 1 ? 'month' : 'months',
            ComparisonMetricKey::FailedEndpointCount => $value === 1 ? 'failed run' : 'failed runs',
            default => $value === 1 ? 'position' : 'positions',
        };

        return $value.' '.$noun;
    }
}
