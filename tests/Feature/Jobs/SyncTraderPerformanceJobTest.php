<?php

use App\Jobs\SyncTraderPerformanceJob;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformancePoint;
use App\Models\Trader;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

it('is unique per trader and leaves rate limiting to the per-request throttle', function () {
    $trader = Trader::factory()->create();
    $job = new SyncTraderPerformanceJob($trader);

    expect($job->uniqueId())->toBe((string) $trader->id)
        ->and(method_exists($job, 'middleware'))->toBeFalse()
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and(RateLimiter::limiter('etoro-api'))->not->toBeNull();
});

it('limits the shared eToro budget to ETORO_REQUESTS_PER_MINUTE', function () {
    config(['etoro.requests_per_minute' => 45]);

    $limit = RateLimiter::limiter('etoro-api')(new SyncTraderPerformanceJob(Trader::factory()->create()));

    expect($limit->maxAttempts)->toBe(45)
        ->and($limit->decaySeconds)->toBe(60);
});

it('closes the ImportRuns its interrupted attempt left running when it fails', function (Throwable $exception, string $interruption) {
    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $running = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Running,
        'metadata' => ['query' => ['trader_id' => $job->trader->id], 'queue_job_uuid' => $job->job->uuid()],
    ]);

    $job->failed($exception);

    $running->refresh();
    expect($running->status)->toBe(ImportRunStatus::Failed)
        ->and($running->finished_at)->not->toBeNull()
        ->and($running->failure_count)->toBe(1)
        ->and($running->error_summary)->toBe('Queued sync was interrupted before it could record its outcome.')
        ->and($running->metadata)->toMatchArray([
            'query' => ['trader_id' => $job->trader->id],
            'stop_reason' => 'interrupted',
            'interruption' => $interruption,
        ]);
})->with([
    'worker timeout' => fn () => [new TimeoutExceededException('timed out'), 'timeout'],
    'max attempts' => fn () => [new MaxAttemptsExceededException('too many attempts'), 'max_attempts'],
    'other exception' => fn () => [new RuntimeException('boom'), 'job_failed'],
]);

it('leaves finished runs and runs of other jobs untouched when it fails', function () {
    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $finished = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Completed,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
        'finished_at' => now()->subMinute(),
    ]);
    $otherJob = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => 'another-job-uuid'],
    ]);
    $synchronous = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Running,
        'metadata' => ['query' => ['trader_id' => $job->trader->id]],
    ]);

    $job->failed(new TimeoutExceededException('timed out'));

    expect($finished->fresh()->status)->toBe(ImportRunStatus::Completed)
        ->and($finished->fresh()->finished_at->equalTo($finished->finished_at))->toBeTrue()
        ->and($finished->fresh()->metadata)->toBe(['queue_job_uuid' => $job->job->uuid()])
        ->and($otherJob->fresh()->status)->toBe(ImportRunStatus::Running)
        ->and($synchronous->fresh()->status)->toBe(ImportRunStatus::Running);
});

it('does nothing on failure without a queue job', function () {
    $running = ImportRun::factory()->create(['type' => 'performance', 'status' => ImportRunStatus::Running]);

    (new SyncTraderPerformanceJob(Trader::factory()->create()))->failed(new TimeoutExceededException('timed out'));

    expect($running->fresh()->status)->toBe(ImportRunStatus::Running);
});

it('tags its runs with the queue job uuid and closes runs a killed earlier attempt left running', function () {
    Http::fake(['*' => Http::response([], 403)]);
    $job = (new SyncTraderPerformanceJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $orphan = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
    ]);

    app()->call([$job, 'handle']);

    expect($orphan->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($orphan->fresh()->metadata['interruption'])->toBe('attempt_interrupted')
        ->and(ImportRun::where('type', 'performance')->whereKeyNot($orphan->id)->get())
        ->each(fn ($run) => $run->metadata->queue_job_uuid->toBe($job->job->uuid())
            ->and($run->status)->not->toBe(ImportRunStatus::Running));
});

it('times out below retry_after and fails instead of retrying on timeout', function () {
    $job = new SyncTraderPerformanceJob(Trader::factory()->create());

    expect($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->timeout)->toBeGreaterThan(60)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('closes its running run when the worker fails it on timeout', function () {
    // On a timeout Job::fail() first rolls the failed-jobs connection back to
    // level 0 (the killed attempt's open transaction), which would also undo
    // RefreshDatabase's test transaction; only that rollback is disabled here.
    config(['queue.failed.driver' => 'null']);
    $trader = Trader::factory()->create();
    Queue::connection('database')->push(new SyncTraderPerformanceJob($trader));
    $queueJob = Queue::connection('database')->pop();
    $running = ImportRun::factory()->create([
        'type' => 'performance',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $queueJob->uuid()],
    ]);

    // What Worker::registerTimeoutHandler() does when failOnTimeout is set.
    $queueJob->fail(new TimeoutExceededException('timed out'));

    expect($queueJob->payload())->toMatchArray(['timeout' => 80, 'failOnTimeout' => true])
        ->and($running->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($running->fresh()->metadata['interruption'])->toBe('timeout');
});
