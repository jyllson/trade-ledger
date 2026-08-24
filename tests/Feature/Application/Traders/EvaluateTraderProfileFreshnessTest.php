<?php

use App\Application\Traders\EvaluateTraderProfileFreshness;
use App\Application\Traders\ProfileFreshness;
use Illuminate\Support\Carbon;

it('returns never_synced for a null profile_synced_at', function (): void {
    $result = (new EvaluateTraderProfileFreshness)->handle(null);

    expect($result)->toBe(ProfileFreshness::NeverSynced);
});

it('returns fresh for a profile synced moments ago', function (): void {
    $now = Carbon::parse('2026-08-21T12:00:00+00:00');
    $syncedAt = Carbon::parse('2026-08-21T11:59:00+00:00');

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Fresh);
});

it('returns fresh at exactly one second before the 24-hour boundary', function (): void {
    $syncedAt = Carbon::parse('2026-08-21T12:00:00+00:00');
    $now = $syncedAt->copy()->addHours(24)->subSecond();

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Fresh);
});

it('returns fresh at EXACTLY the 24-hour boundary (inclusive)', function (): void {
    $syncedAt = Carbon::parse('2026-08-21T12:00:00+00:00');
    $now = $syncedAt->copy()->addHours(24);

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Fresh);
});

it('returns stale at one second past the 24-hour boundary', function (): void {
    $syncedAt = Carbon::parse('2026-08-21T12:00:00+00:00');
    $now = $syncedAt->copy()->addHours(24)->addSecond();

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Stale);
});

it('returns stale for a profile synced many days ago', function (): void {
    $syncedAt = Carbon::parse('2026-08-01T00:00:00+00:00');
    $now = Carbon::parse('2026-08-21T12:00:00+00:00');

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Stale);
});

it('defaults $now to Carbon::now(), respecting frozen Laravel test time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21T12:00:00+00:00'));

    try {
        $syncedAt = Carbon::parse('2026-08-20T12:00:00+00:00');

        $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt);

        expect($result)->toBe(ProfileFreshness::Fresh);
    } finally {
        Carbon::setTestNow(null);
    }
});

it('treats a profile_synced_at one minute in the future as fresh (clock skew, not aged data)', function (): void {
    $now = Carbon::parse('2026-08-21T12:00:00+00:00');
    $syncedAt = $now->copy()->addMinute();

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Fresh);
});

it('treats a profile_synced_at MORE THAN 24 hours in the future as still fresh — "aged" means elapsed past time only, never a signed/absolute distance', function (): void {
    $now = Carbon::parse('2026-08-21T12:00:00+00:00');
    $syncedAt = $now->copy()->addDays(10);

    $result = (new EvaluateTraderProfileFreshness)->handle($syncedAt, $now);

    expect($result)->toBe(ProfileFreshness::Fresh);
});
