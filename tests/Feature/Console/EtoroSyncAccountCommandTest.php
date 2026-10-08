<?php

use App\Jobs\SyncEtoroAccountJob;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__.'/../Application/Account/AccountFixtures.php';

function callSyncAccount(array $parameters = []): array
{
    $buffer = new BufferedOutput;
    $exitCode = Artisan::call('etoro:sync-account', $parameters, $buffer);

    return [$exitCode, $buffer->fetch()];
}

beforeEach(function () {
    configureEtoroForAccountTests();
    Http::preventStrayRequests();
    Sleep::fake();
    Queue::fake();
});

it('requires exactly one of --demo or --real', function (array $parameters) {
    [$exitCode] = callSyncAccount($parameters);

    expect($exitCode)->toBe(2);
    Queue::assertNothingPushed();
})->with([
    'neither' => [[]],
    'both' => [['--demo' => true, '--real' => true]],
]);

it('refuses --real in code without sending or queueing anything', function (array $parameters) {
    [$exitCode, $output] = callSyncAccount($parameters);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Real account sync is disabled')
        ->and(ImportRun::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'queued' => [['--real' => true]],
    'now' => [['--real' => true, '--now' => true]],
]);

it('queues the demo account sync by default', function () {
    [$exitCode] = callSyncAccount(['--demo' => true]);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncEtoroAccountJob::class, 1);
    Http::assertNothingSent();
});

it('runs synchronously with --now and prints counts only', function () {
    fakeDemoAccountPnl();

    [$exitCode, $output] = callSyncAccount(['--demo' => true, '--now' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('completed')
        ->and($output)->toContain('new #'.AccountSnapshot::sole()->id)
        ->and($output)->not->toContain('5,000.25')
        ->and($output)->not->toContain('trader_001');
    Queue::assertNothingPushed();
});

it('fails with --now when the sync fails', function () {
    fakeDemoAccountPnl([], 403);

    [$exitCode, $output] = callSyncAccount(['--demo' => true, '--now' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('not_authorized');
});

it('does nothing when the integration is disabled', function () {
    config(['etoro.enabled' => false]);

    [$exitCode, $output] = callSyncAccount(['--demo' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('disabled');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('schedules a daily demo account sync at 03:30 UTC without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'etoro:sync-account --demo'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue();

    expect(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains($event->command ?? '', '--real')))->toBeFalse();
});
