<?php

use App\Application\Account\AccountSyncNotEnabled;
use App\Application\Account\QueueEtoroAccountSync;
use App\Etoro\EtoroEnvironment;
use App\Jobs\SyncEtoroAccountJob;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Application/Account/AccountFixtures.php';

beforeEach(function () {
    configureEtoroForAccountTests();
    Http::preventStrayRequests();
    Sleep::fake();
});

it('stores the demo account snapshot', function () {
    fakeDemoAccountPnl(accountPnlPayload('account-pnl-empty.json'));

    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Demo))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(AccountSnapshot::count())->toBe(1)
        ->and(ImportRun::where('type', 'account')->sole()->status)->toBe(ImportRunStatus::Completed);
});

it('releases a rate-limited request honouring Retry-After', function () {
    fakeDemoAccountPnl([], 429, ['Retry-After' => '42']);

    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Demo))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(42);
});

it('does not release a final failure', function () {
    fakeDemoAccountPnl([], 403);

    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Demo))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect(ImportRun::where('type', 'account')->sole()->metadata['stop_reason'])->toBe('not_authorized');
});

it('never reaches eToro for the REAL account', function () {
    fakeDemoAccountPnl();

    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Real))->withFakeQueueInteractions();

    expect(fn () => app()->call([$job, 'handle']))->toThrow(AccountSyncNotEnabled::class);
    Http::assertNothingSent();
    expect(ImportRun::count())->toBe(0);
});

it('is unique per environment, bounded in time and below retry_after', function () {
    $job = new SyncEtoroAccountJob(EtoroEnvironment::Demo);

    expect($job->uniqueId())->toBe('demo')
        ->and(method_exists($job, 'middleware'))->toBeFalse()
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and($job->failOnTimeout)->toBeTrue();
});

it('closes the ImportRun its interrupted attempt left running when it fails', function (Throwable $exception, string $interruption) {
    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Demo))->setJob(fakeQueueJobWithUuid());
    $running = ImportRun::factory()->create([
        'type' => 'account',
        'status' => ImportRunStatus::Running,
        'metadata' => ['query' => ['environment' => 'demo'], 'queue_job_uuid' => $job->job->uuid()],
    ]);
    $otherType = ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
    ]);

    $job->failed($exception);

    expect($running->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($running->fresh()->metadata)->toMatchArray(['stop_reason' => 'interrupted', 'interruption' => $interruption])
        ->and($otherType->fresh()->status)->toBe(ImportRunStatus::Running);
})->with([
    'worker timeout' => fn () => [new TimeoutExceededException('timed out'), 'timeout'],
    'max attempts' => fn () => [new MaxAttemptsExceededException('too many attempts'), 'max_attempts'],
    'other exception' => fn () => [new RuntimeException('boom'), 'job_failed'],
]);

it('tags its run with the queue job uuid and closes a run a killed earlier attempt left running', function () {
    fakeDemoAccountPnl([], 403);
    $job = (new SyncEtoroAccountJob(EtoroEnvironment::Demo))->setJob(fakeQueueJobWithUuid());
    $orphan = ImportRun::factory()->create([
        'type' => 'account',
        'status' => ImportRunStatus::Running,
        'metadata' => ['queue_job_uuid' => $job->job->uuid()],
    ]);

    app()->call([$job, 'handle']);

    expect($orphan->fresh()->metadata['interruption'])->toBe('attempt_interrupted')
        ->and(ImportRun::where('type', 'account')->whereKeyNot($orphan->id)->sole()->metadata['queue_job_uuid'])->toBe($job->job->uuid());
});

it('queues only enabled environments, and nothing when the integration is disabled', function () {
    Queue::fake();

    expect(app(QueueEtoroAccountSync::class)->handle(EtoroEnvironment::Demo))->toBeTrue();
    Queue::assertPushed(SyncEtoroAccountJob::class, fn (SyncEtoroAccountJob $job) => $job->environment === EtoroEnvironment::Demo);

    expect(fn () => app(QueueEtoroAccountSync::class)->handle(EtoroEnvironment::Real))->toThrow(AccountSyncNotEnabled::class);
    Queue::assertPushed(SyncEtoroAccountJob::class, 1);

    config(['etoro.enabled' => false]);
    expect(app(QueueEtoroAccountSync::class)->handle(EtoroEnvironment::Demo))->toBeFalse();
    Queue::assertPushed(SyncEtoroAccountJob::class, 1);
});
