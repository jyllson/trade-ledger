<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Every metric of the comparison (PROJECT.md §14), in display order. Each
 * belongs to exactly one dimension (docs/DECISIONS.md D-047).
 */
enum ComparisonMetricKey: string
{
    case CumulativeReturn = 'cumulative_return';
    case Trailing12MonthReturn = 'trailing_12m_return';
    case Trailing24MonthReturn = 'trailing_24m_return';
    case AverageMonthlyReturn = 'average_monthly_return';
    case MedianMonthlyReturn = 'median_monthly_return';
    case ProfitableMonthRatio = 'profitable_month_ratio';

    case DailyMaxDrawdown = 'daily_max_drawdown';
    case MonthlyMaxDrawdown = 'monthly_max_drawdown';
    case MonthlyVolatility = 'monthly_volatility';
    case AnnualizedVolatility = 'annualized_volatility';
    case RiskScore = 'risk_score';
    case LargestPosition = 'largest_position';
    case TopThreeConcentration = 'top_three_concentration';
    case WeightedLeverage = 'weighted_leverage';

    case PositiveMonthRatio = 'positive_month_ratio';
    case LongestLosingStreak = 'longest_losing_streak';
    case MonthlyReturnDispersion = 'monthly_return_dispersion';
    case BestMonthDependency = 'best_month_dependency';
    case ReturnExcludingBestMonth = 'return_excluding_best_month';
    case ReturnExcludingBestThreeMonths = 'return_excluding_best_three_months';

    case CoverageAt200 = 'coverage_at_200';
    case CoverageAt500 = 'coverage_at_500';
    case CoverageAt1000 = 'coverage_at_1000';
    case MinimumFor90 = 'minimum_for_90';
    case MinimumFor95 = 'minimum_for_95';
    case MinimumFor99 = 'minimum_for_99';
    case MinimumForAllVisible = 'minimum_for_all_visible';
    case SkippedCountAt200 = 'skipped_count_at_200';
    case SkippedWeightAt200 = 'skipped_weight_at_200';
    case SkippedCountAt500 = 'skipped_count_at_500';
    case SkippedWeightAt500 = 'skipped_weight_at_500';
    case SkippedCountAt1000 = 'skipped_count_at_1000';
    case SkippedWeightAt1000 = 'skipped_weight_at_1000';

    case PerformanceLastSuccessfulSync = 'performance_last_successful_sync';
    case PortfolioLastSuccessfulSync = 'portfolio_last_successful_sync';
    case PerformanceVisibility = 'performance_visibility';
    case PortfolioVisibility = 'portfolio_visibility';
    case StaleDataWarning = 'stale_data_warning';
    case CompletenessScore = 'completeness_score';
    case FailedEndpointCount = 'failed_endpoint_count';

    public function dimension(): ComparisonDimension
    {
        return match ($this) {
            self::CumulativeReturn,
            self::Trailing12MonthReturn,
            self::Trailing24MonthReturn,
            self::AverageMonthlyReturn,
            self::MedianMonthlyReturn,
            self::ProfitableMonthRatio => ComparisonDimension::Performance,

            self::DailyMaxDrawdown,
            self::MonthlyMaxDrawdown,
            self::MonthlyVolatility,
            self::AnnualizedVolatility,
            self::RiskScore,
            self::LargestPosition,
            self::TopThreeConcentration,
            self::WeightedLeverage => ComparisonDimension::Risk,

            self::PositiveMonthRatio,
            self::LongestLosingStreak,
            self::MonthlyReturnDispersion,
            self::BestMonthDependency,
            self::ReturnExcludingBestMonth,
            self::ReturnExcludingBestThreeMonths => ComparisonDimension::Consistency,

            self::CoverageAt200,
            self::CoverageAt500,
            self::CoverageAt1000,
            self::MinimumFor90,
            self::MinimumFor95,
            self::MinimumFor99,
            self::MinimumForAllVisible,
            self::SkippedCountAt200,
            self::SkippedWeightAt200,
            self::SkippedCountAt500,
            self::SkippedWeightAt500,
            self::SkippedCountAt1000,
            self::SkippedWeightAt1000 => ComparisonDimension::Copyability,

            self::PerformanceLastSuccessfulSync,
            self::PortfolioLastSuccessfulSync,
            self::PerformanceVisibility,
            self::PortfolioVisibility,
            self::StaleDataWarning,
            self::CompletenessScore,
            self::FailedEndpointCount => ComparisonDimension::DataQuality,
        };
    }

    public function unit(): MetricUnit
    {
        return match ($this) {
            self::LongestLosingStreak,
            self::SkippedCountAt200,
            self::SkippedCountAt500,
            self::SkippedCountAt1000,
            self::FailedEndpointCount => MetricUnit::Count,

            self::MinimumFor90,
            self::MinimumFor95,
            self::MinimumFor99,
            self::MinimumForAllVisible => MetricUnit::Money,

            self::WeightedLeverage => MetricUnit::Multiple,

            self::PerformanceLastSuccessfulSync,
            self::PortfolioLastSuccessfulSync => MetricUnit::Timestamp,

            self::PerformanceVisibility,
            self::PortfolioVisibility => MetricUnit::Visibility,

            self::StaleDataWarning => MetricUnit::Flag,

            default => MetricUnit::Fraction,
        };
    }
}
