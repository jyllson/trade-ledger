<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Completeness of one concentration dimension or of the leverage result.
 *
 * - Complete: every usable position weight has the needed data.
 * - Partial: metrics are computed, but part of the weight is unknown
 *   (shown as an explicit "unknown" group / unknown weight).
 * - Unavailable: no metrics; see ExposureUnavailableReason.
 */
enum ExposureStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
