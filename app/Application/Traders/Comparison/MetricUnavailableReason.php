<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

enum MetricUnavailableReason: string
{
    /** No stored performance points of the needed granularity. */
    case NoPerformanceData = 'no_performance_data';

    /** Fewer complete periods than the metric needs (details: required/available). */
    case InsufficientHistory = 'insufficient_history';

    /** The data source does not provide the value (e.g. eToro risk score is not collected). */
    case NotProvidedBySource = 'not_provided_by_source';

    /** No stored portfolio snapshot. */
    case NoStoredSnapshot = 'no_stored_snapshot';

    /** Empty or cash-only snapshot (D-041). */
    case NoInvestedWeight = 'no_invested_weight';

    /** Invested weight exists, but none carries the needed data (D-041). */
    case NoData = 'no_data';

    /** Part of the invested weight has unknown leverage, so §13.7 is not determinable (D-041 point 5). */
    case LeverageNotDeterminable = 'leverage_not_determinable';

    /** The snapshot has no positive position weight — no coverage target is reachable (D-042). */
    case NoPositiveWeight = 'no_positive_weight';

    /** The figure does not fit the representable money range (D-043). */
    case OutOfRange = 'out_of_range';

    /** A share of a zero or negative compounded return has no meaning (D-047). */
    case NonPositiveReturn = 'non_positive_return';

    /** The sync never succeeded / never reached the API. */
    case NeverSynced = 'never_synced';

    public function label(): string
    {
        return match ($this) {
            self::NoPerformanceData => 'No stored performance data of this granularity',
            self::InsufficientHistory => 'Not enough complete periods',
            self::NotProvidedBySource => 'Not collected from the data source',
            self::NoStoredSnapshot => 'No stored portfolio snapshot',
            self::NoInvestedWeight => 'Snapshot has no invested weight (empty or cash only)',
            self::NoData => 'No position carries the needed data',
            self::LeverageNotDeterminable => 'Leverage unknown for part of the invested weight',
            self::NoPositiveWeight => 'Snapshot has no positive position weight',
            self::OutOfRange => 'Out of range — practically unreachable',
            self::NonPositiveReturn => 'Undefined for a zero or negative compounded return',
            self::NeverSynced => 'Never synced successfully',
        };
    }
}
