<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Freshness of one stored source (performance or portfolio) at the
 * comparison instant (docs/DECISIONS.md D-047). Anything but Fresh raises
 * the stale-data warning.
 */
enum DataFreshness: string
{
    case Fresh = 'fresh';

    /** Last successful sync is older than BuildTraderComparison::STALE_AFTER_HOURS. */
    case Stale = 'stale';

    /** The last sync found the source private / not found — stored data is the last known (D-045). */
    case NoLongerVisible = 'no_longer_visible';

    case NeverSynced = 'never_synced';

    public function isFresh(): bool
    {
        return $this === self::Fresh;
    }
}
