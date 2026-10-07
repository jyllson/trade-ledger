<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * The analysis profile criteria (PROJECT.md §11 `analysis_profiles`) in
 * evaluation and display order, each mapped to the comparison metric(s)
 * it reads (docs/DECISIONS.md D-048).
 */
enum ProfileCriterion: string
{
    case TargetCoverageAtBudget = 'target_coverage_at_budget';
    case MaximumDrawdown = 'maximum_drawdown';
    case MaximumRiskScore = 'maximum_risk_score';
    case MaximumSinglePosition = 'maximum_single_position';
    case MinimumHistoryMonths = 'minimum_history_months';
    case MinimumPositiveMonths = 'minimum_positive_months';
    case MaximumAllocationPerTrader = 'maximum_allocation_per_trader';

    public function label(): string
    {
        return match ($this) {
            self::TargetCoverageAtBudget => 'Target coverage at budget',
            self::MaximumDrawdown => 'Maximum drawdown',
            self::MaximumRiskScore => 'Maximum risk score',
            self::MaximumSinglePosition => 'Maximum single position',
            self::MinimumHistoryMonths => 'Minimum history',
            self::MinimumPositiveMonths => 'Minimum positive months',
            self::MaximumAllocationPerTrader => 'Maximum allocation per trader',
        };
    }

    /**
     * @return list<ComparisonMetricKey> the metrics the criterion reads (none for the informational one)
     */
    public function metricKeys(): array
    {
        return match ($this) {
            self::TargetCoverageAtBudget => [ComparisonMetricKey::CoverageAtProfileBudget, ComparisonMetricKey::MinimumForProfileTarget],
            self::MaximumDrawdown => [ComparisonMetricKey::DailyMaxDrawdown, ComparisonMetricKey::MonthlyMaxDrawdown],
            self::MaximumRiskScore => [ComparisonMetricKey::RiskScore],
            self::MaximumSinglePosition => [ComparisonMetricKey::LargestPosition],
            self::MinimumHistoryMonths, self::MinimumPositiveMonths => [ComparisonMetricKey::PositiveMonthRatio],
            self::MaximumAllocationPerTrader => [],
        };
    }
}
