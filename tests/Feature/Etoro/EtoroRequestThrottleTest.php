<?php

use App\Application\Traders\SyncTraderPerformanceStopReason;
use App\Application\Traders\SyncTraderPortfolioStopReason;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\EtoroRequestThrottle;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Jobs\SyncTraderPerformanceJob;
use App\Jobs\SyncTraderPortfolioJob;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.requests_per_minute' => 45,
        'etoro.market_data_requests_per_minute' => 90,
    ]);
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
});

function etoroPermitsUsed(string $limiterName): int
{
    return RateLimiter::attempts($limiterName.':');
}

function exhaustEtoroBudget(string $limiterName, int $permits): void
{
    for ($i = 0; $i < $permits; $i++) {
        RateLimiter::hit($limiterName.':', 60);
    }
}

it('spends one permit per HTTP request', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $client = app(EtoroClient::class);
    $client->authenticatedUser();
    $client->authenticatedUser();
    $client->authenticatedUser();

    Http::assertSentCount(3);
    expect(etoroPermitsUsed(EtoroRequestThrottle::DEFAULT_LIMITER))->toBe(3)
        ->and(etoroPermitsUsed(EtoroRequestThrottle::MARKET_DATA_LIMITER))->toBe(0);
});

it('spends one permit per internal retry attempt', function () {
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => app(EtoroClient::class)->authenticatedUser())->toThrow(EtoroRequestException::class);

    Http::assertSentCount(3);
    expect(etoroPermitsUsed(EtoroRequestThrottle::DEFAULT_LIMITER))->toBe(3);
});

it('charges market-data endpoints to their own budget', function () {
    Http::fake(['*' => Http::response([], 200)]);

    app(EtoroClient::class)->instrumentDisplayData([1, 2]);
    app(EtoroClient::class)->instrumentTypes();

    expect(etoroPermitsUsed(EtoroRequestThrottle::MARKET_DATA_LIMITER))->toBe(2)
        ->and(etoroPermitsUsed(EtoroRequestThrottle::DEFAULT_LIMITER))->toBe(0);
});

it('refuses at once, without waiting or sending, when the budget is exhausted', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    config(['etoro.requests_per_minute' => 2]);
    exhaustEtoroBudget(EtoroRequestThrottle::DEFAULT_LIMITER, 2);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected the local budget to be exhausted.');
    } catch (EtoroRequestException $exception) {
        expect($exception->category)->toBe(EtoroErrorCategory::RateLimited)
            ->and($exception->locallyThrottled)->toBeTrue()
            ->and($exception->httpStatus)->toBeNull()
            ->and($exception->attemptCount)->toBe(0)
            ->and($exception->retryAfterSeconds)->toBeGreaterThan(0)
            ->and($exception->retryAfterSeconds)->toBeLessThanOrEqual(60);
    }

    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});

it('reports the attempts already sent when a retry is refused locally', function () {
    Http::fake(['*' => Http::response([], 503)]);
    config(['etoro.requests_per_minute' => 1]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected the retry to be refused locally.');
    } catch (EtoroRequestException $exception) {
        expect($exception->locallyThrottled)->toBeTrue()
            ->and($exception->category)->toBe(EtoroErrorCategory::RateLimited)
            ->and($exception->attemptCount)->toBe(1)
            ->and($exception->requestId)->not->toBeNull();
    }

    Http::assertSentCount(1);
});

it('records the sent attempt in the ImportRun when a retry is refused locally', function () {
    Http::fake(['*' => Http::response([], 503)]);
    config(['etoro.requests_per_minute' => 1]);
    Trader::factory()->create(['username' => 'trader_001']);

    $this->artisan('etoro:sync-portfolio', ['username' => 'trader_001', '--now' => true])->run();

    Http::assertSentCount(1);
    $importRun = ImportRun::where('type', 'portfolio')->sole();
    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->metadata['stop_reason'])->toBe(SyncTraderPortfolioStopReason::TemporarilyUnavailable->value)
        ->and($importRun->request_count)->toBe(1);
});

it('applies the budget to the synchronous sync command path', function () {
    Http::fake(['*' => Http::response([], 200)]);
    config(['etoro.requests_per_minute' => 1]);
    exhaustEtoroBudget(EtoroRequestThrottle::DEFAULT_LIMITER, 1);
    Trader::factory()->create(['username' => 'trader_001']);

    $this->artisan('etoro:sync-performance', ['username' => 'trader_001', '--now' => true])
        ->expectsOutputToContain('Re-run later')
        ->assertExitCode(1);

    Http::assertNothingSent();
    Sleep::assertNeverSlept();
    $importRun = ImportRun::where('type', 'performance')->sole();
    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->metadata['stop_reason'])->toBe(SyncTraderPerformanceStopReason::TemporarilyUnavailable->value)
        ->and($importRun->request_count)->toBe(0);
});

it('releases a queued performance job when the local budget is exhausted', function () {
    config(['etoro.requests_per_minute' => 1]);
    exhaustEtoroBudget(EtoroRequestThrottle::DEFAULT_LIMITER, 1);

    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    Http::assertNothingSent();
    Sleep::assertNeverSlept();
    expect(ImportRun::where('status', ImportRunStatus::Running)->count())->toBe(0);
});

it('releases a queued portfolio job when the local budget is exhausted', function () {
    config(['etoro.requests_per_minute' => 1]);
    exhaustEtoroBudget(EtoroRequestThrottle::DEFAULT_LIMITER, 1);

    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    Http::assertNothingSent();
    Sleep::assertNeverSlept();
    expect(ImportRun::where('status', ImportRunStatus::Running)->count())->toBe(0);
});
