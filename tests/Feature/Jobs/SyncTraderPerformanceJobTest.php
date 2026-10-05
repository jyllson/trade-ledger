<?php

use App\Jobs\SyncTraderPerformanceJob;
use App\Models\PerformancePoint;
use App\Models\Trader;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

it('syncs the monthly then the daily series', function () {
    Http::fake([
        '*/gain/monthly*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-monthly.json')), true), 200),
        '*/gain/daily*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-daily.json')), true), 200),
    ]);
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    $job = (new SyncTraderPerformanceJob($trader))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(PerformancePoint::where('granularity', 'monthly')->count())->toBe(26)
        ->and(PerformancePoint::where('granularity', 'daily')->count())->toBe(14);
});

it('releases the whole job when the daily request is temporarily unavailable', function () {
    Http::fake([
        '*/gain/monthly*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-monthly.json')), true), 200),
        '*/gain/daily*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    $job = (new SyncTraderPerformanceJob(Trader::factory()->create(['username' => 'trader_001'])))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 30);
});

it('releases a rate-limited sync honouring Retry-After', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 42);
});

it('falls back to a default release delay without Retry-After', function () {
    Http::fake(['*' => Http::sequence()->push([], 503)->push([], 503)->push([], 503)]);

    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 60);
});

it('does not release a private trader — the outcome is final', function () {
    Http::fake(['*' => Http::response([], 403)]);

    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    Http::assertSentCount(1);
});

it('is unique per trader and uses the shared eToro rate limiter', function () {
    $trader = Trader::factory()->create();
    $job = new SyncTraderPerformanceJob($trader);

    expect($job->uniqueId())->toBe((string) $trader->id)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and(RateLimiter::limiter('etoro-api'))->not->toBeNull();
});

it('limits the shared eToro budget to ETORO_REQUESTS_PER_MINUTE', function () {
    config(['etoro.requests_per_minute' => 45]);

    $limit = RateLimiter::limiter('etoro-api')(new SyncTraderPerformanceJob(Trader::factory()->create()));

    expect($limit->maxAttempts)->toBe(45)
        ->and($limit->decaySeconds)->toBe(60);
});
