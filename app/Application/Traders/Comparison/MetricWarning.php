<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Caveats attached to a metric value; the UI must show them next to it
 * (docs/DECISIONS.md D-047).
 */
enum MetricWarning: string
{
    /** The observation includes a partial first period (activity started mid-period, D-032). */
    case IncludesPartialStartPeriod = 'includes_partial_start_period';

    /** The observation includes the period still in progress at sync time (D-032). */
    case IncludesInProgressPeriod = 'includes_in_progress_period';

    /** Performance is now private / not found: the value is from the last known history. */
    case PerformanceNoLongerVisible = 'performance_no_longer_visible';

    /** The last successful performance sync is older than the stale threshold. */
    case PerformanceStale = 'performance_stale';

    /** Portfolio is now private / not found: last known snapshot, may be outdated (D-045). */
    case SnapshotNoLongerVisible = 'snapshot_no_longer_visible';

    /** The snapshot was last confirmed longer ago than the stale threshold. */
    case SnapshotStale = 'snapshot_stale';

    /** Copy figures are estimates — the snapshot has incomplete/irregular data (D-042 is_estimate). */
    case EstimatedFromIncompleteSnapshot = 'estimated_from_incomplete_snapshot';

    /** Positions with unknown or negative weight are excluded from the weighted metric (D-041). */
    case PositionsWithoutUsableWeightExcluded = 'positions_without_usable_weight_excluded';

    /** Drawdown at monthly granularity — not an intraday or daily drawdown (§13.3). */
    case MonthlyGranularityNotIntraday = 'monthly_granularity_not_intraday';
}
