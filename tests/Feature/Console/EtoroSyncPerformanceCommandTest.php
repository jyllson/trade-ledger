<?php

use App\Jobs\SyncTraderPerformanceJob;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

function callSyncPerformance(array $parameters = []): array
{
    $buffer = new BufferedOutput;
    $exitCode = Artisan::call('etoro:sync-performance', $parameters, $buffer);

    return [$exitCode, $buffer->fetch()];
}

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
    Queue::fake();
});

it('requires exactly one of a username or --watched', function (array $parameters) {
    [$exitCode] = callSyncPerformance($parameters);

    expect($exitCode)->toBe(2);
    Queue::assertNothingPushed();
})->with([
    'neither' => [[]],
    'both' => [['username' => 'trader_001', '--watched' => true]],
]);

it('rejects an unknown username without creating a trader or queueing', function () {
    [$exitCode, $output] = callSyncPerformance(['username' => 'nobody_here']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('No stored trader')
        ->and(Trader::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues one job for a stored trader by default', function () {
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode] = callSyncPerformance(['username' => 'trader_001']);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncTraderPerformanceJob::class, fn (SyncTraderPerformanceJob $job) => $job->trader->is($trader));
    Http::assertNothingSent();
});

it('queues jobs only for watched traders with --watched', function () {
    $watched = Trader::factory()->count(2)->create(['status' => TraderStatus::Watched]);
    Trader::factory()->create(['status' => TraderStatus::Candidate]);
    Trader::factory()->create(['status' => TraderStatus::Ignored]);

    [$exitCode] = callSyncPerformance(['--watched' => true]);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncTraderPerformanceJob::class, 2);
    Queue::assertPushed(SyncTraderPerformanceJob::class, fn (SyncTraderPerformanceJob $job) => $watched->contains($job->trader));
});

it('does nothing and queues nothing when the integration is disabled', function () {
    config(['etoro.enabled' => false]);
    Trader::factory()->create(['status' => TraderStatus::Watched]);

    [$exitCode, $output] = callSyncPerformance(['--watched' => true]);

    expect($exitCode)->toBe(0)->and($output)->toContain('disabled');
    Queue::assertNothingPushed();
});

it('runs synchronously with --now and prints only sanitized outcomes, never gain values', function () {
    Http::fake(['*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-monthly.json')), true), 200)]);
    Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode, $output] = callSyncPerformance(['username' => 'trader_001', '--now' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('completed')
        ->and($output)->toContain('26')
        ->and($output)->not->toContain('trader_001')
        ->and($output)->not->toContain('0.0125');
    Queue::assertNothingPushed();
});

it('returns a failure exit code with --now when a sync does not complete', function () {
    Http::fake(['*' => Http::response([], 403)]);
    Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode, $output] = callSyncPerformance(['username' => 'trader_001', '--now' => true]);

    expect($exitCode)->toBe(1)->and($output)->toContain('not_visible');
});

it('schedules the watched-trader sync daily at 03:00 UTC', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'etoro:sync-performance --watched'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue();
});
