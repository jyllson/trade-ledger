<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * State of one completeness check the application collects (D-047). Only
 * Present counts toward the score; the other states say why a check does
 * not count. Checks the application does not collect at all are not
 * states — they are listed separately as not supported
 * (CompletenessUnsupportedReason) and stay out of the denominator.
 */
enum CompletenessState: string
{
    /** Available and fresh. */
    case Present = 'present';

    /** Stored, but older than the freshness threshold or no longer visible. */
    case Stale = 'stale';

    /** Collected by the application, but not available for this trader. */
    case Missing = 'missing';
}
