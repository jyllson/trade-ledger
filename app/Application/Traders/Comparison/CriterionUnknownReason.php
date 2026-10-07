<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Why a criterion is Unknown (D-048).
 */
enum CriterionUnknownReason: string
{
    /** The metric the criterion reads is unavailable (details: metric_unavailable_reason). */
    case MetricUnavailable = 'metric_unavailable';

    /** The metric was computed from partly unknown or estimated input, so the threshold check is not reliable. */
    case MetricPartial = 'metric_partial';

    public function label(): string
    {
        return match ($this) {
            self::MetricUnavailable => 'The metric is unavailable',
            self::MetricPartial => 'The metric is only partially determined',
        };
    }
}
