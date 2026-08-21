<?php

declare(strict_types=1);

namespace App\Application\Traders;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single, authoritative business rule for whether a Trader's observed
 * eToro profile is stale. The Filament UI never computes this itself — it
 * only ever asks this evaluator, so the 24-hour boundary lives in exactly
 * one place.
 */
final class EvaluateTraderProfileFreshness
{
    private const STALE_AFTER_SECONDS = 24 * 60 * 60;

    /**
     * A profile is stale strictly AFTER 24 hours have ELAPSED since
     * profile_synced_at — exactly 24h00m00s of elapsed age is still fresh.
     * A profile_synced_at in the future (clock skew, not aged data) is
     * always fresh, no matter how far ahead — "aged" only ever means
     * elapsed past time, never a signed/absolute distance. $now defaults
     * to Carbon::now(), which already respects Carbon::setTestNow() under
     * Pest, but may also be passed explicitly by any injectable/
     * explicit-clock caller.
     */
    public function handle(?CarbonInterface $profileSyncedAt, ?CarbonInterface $now = null): ProfileFreshness
    {
        if ($profileSyncedAt === null) {
            return ProfileFreshness::NeverSynced;
        }

        $now ??= Carbon::now();

        if ($profileSyncedAt->isAfter($now)) {
            return ProfileFreshness::Fresh;
        }

        $elapsedSeconds = $now->diffInSeconds($profileSyncedAt, absolute: true);

        return $elapsedSeconds > self::STALE_AFTER_SECONDS
            ? ProfileFreshness::Stale
            : ProfileFreshness::Fresh;
    }
}
