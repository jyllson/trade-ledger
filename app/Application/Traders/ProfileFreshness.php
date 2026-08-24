<?php

declare(strict_types=1);

namespace App\Application\Traders;

/**
 * A Trader's observed eToro profile freshness relative to
 * profile_synced_at. Never computed by the presentation layer — see
 * EvaluateTraderProfileFreshness.
 */
enum ProfileFreshness: string
{
    case NeverSynced = 'never_synced';
    case Fresh = 'fresh';
    case Stale = 'stale';
}
