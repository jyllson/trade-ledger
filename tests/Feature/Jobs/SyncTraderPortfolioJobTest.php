<?php

use App\Jobs\SyncTraderPortfolioJob;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PortfolioSnapshot;
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

it('imports the live portfolio snapshot', function () {
    Http::fake([
        '*/portfolio/live' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/live-portfolio.json')), true), 200),
        '*/market-data/instruments*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-display-data.json')), true), 200),
        '*/market-data/instrument-types' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-types.json')), true), 200),
    ]);
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    $job = (new SyncTraderPortfolioJob($trader))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(PortfolioSnapshot::where('trader_id', $trader->id)->count())->toBe(1)
        ->and(ImportRun::where('type', 'portfolio')->count())->toBe(1);
});

it('does not release the job when only instrument metadata fails', function () {
    Http::fake([
        '*/portfolio/live' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/live-portfolio.json')), true), 200),
        '*/market-data/*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    $job = (new SyncTraderPortfolioJob(Trader::factory()->create(['username' => 'trader_001'])))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(PortfolioSnapshot::count())->toBe(1);
});

it('releases a rate-limited portfolio request honouring Retry-After', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 42);
});

it('falls back to a default release delay without Retry-After', function () {
    Http::fake(['*' => Http::sequence()->push([], 503)->push([], 503)->push([], 503)]);

    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 60);
});

it('does not release a private portfolio — the outcome is final', function () {
    Http::fake(['*' => Http::response([], 403)]);

    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    Http::assertSentCount(1);
});

it('is unique per trader and leaves rate limiting to the per-request throttle', function () {
    $trader = Trader::factory()->create();
    $job = new SyncTraderPortfolioJob($trader);

    expect($job->uniqueId())->toBe((string) $trader->id)
        ->and(method_exists($job, 'middleware'))->toBeFalse()
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and(RateLimiter::limiter('etoro-api'))->not->toBeNull();
});

it('closes the ImportRuns its interrupted attempt left running when it fails', function (Throwable $exception, string $interruption) {
    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $running = ImportRun::factory()->create([
        'type' => 'portfolio',
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
    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $finished = ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Completed,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
        'finished_at' => now()->subMinute(),
    ]);
    $otherJob = ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => 'another-job-uuid'],
    ]);
    $synchronous = ImportRun::factory()->create([
        'type' => 'portfolio',
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
    $running = ImportRun::factory()->create(['type' => 'portfolio', 'status' => ImportRunStatus::Running]);

    (new SyncTraderPortfolioJob(Trader::factory()->create()))->failed(new TimeoutExceededException('timed out'));

    expect($running->fresh()->status)->toBe(ImportRunStatus::Running);
});

it('tags its runs with the queue job uuid and closes runs a killed earlier attempt left running', function () {
    Http::fake(['*' => Http::response([], 403)]);
    $job = (new SyncTraderPortfolioJob(Trader::factory()->create()))->setJob(fakeQueueJobWithUuid());
    $orphan = ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
    ]);

    app()->call([$job, 'handle']);

    expect($orphan->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($orphan->fresh()->metadata['interruption'])->toBe('attempt_interrupted')
        ->and(ImportRun::where('type', 'portfolio')->whereKeyNot($orphan->id)->get())
        ->each(fn ($run) => $run->metadata->queue_job_uuid->toBe($job->job->uuid())
            ->and($run->status)->not->toBe(ImportRunStatus::Running));
});

it('times out below retry_after and fails instead of retrying on timeout', function () {
    $job = new SyncTraderPortfolioJob(Trader::factory()->create());

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
    Queue::connection('database')->push(new SyncTraderPortfolioJob($trader));
    $queueJob = Queue::connection('database')->pop();
    $running = ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $queueJob->uuid()],
    ]);

    // What Worker::registerTimeoutHandler() does when failOnTimeout is set.
    $queueJob->fail(new TimeoutExceededException('timed out'));

    expect($queueJob->payload())->toMatchArray(['timeout' => 80, 'failOnTimeout' => true])
        ->and($running->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($running->fresh()->metadata['interruption'])->toBe('timeout');
});
