<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Status of one comparison metric (docs/DECISIONS.md D-047).
 *
 * - Available: computed from complete inputs.
 * - Partial: computed, but part of the input is unknown or estimated — the
 *   value is an approximation; see the metric's warnings and details.
 * - Unavailable: no value (never a silent 0); see MetricUnavailableReason.
 */
enum MetricStatus: string
{
    case Available = 'available';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
