<?php

use App\Jobs\SyncTraderPortfolioJob;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

function fakePortfolioCommandEndpoints(): void
{
    Http::fake([
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/live-portfolio.json')), true), 200),
        'https://public-api.etoro.com/api/v1/market-data/instruments*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-display-data.json')), true), 200),
        'https://public-api.etoro.com/api/v1/market-data/instrument-types' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-types.json')), true), 200),
    ]);
}

function callSyncPortfolio(array $parameters = []): array
{
    $buffer = new BufferedOutput;
    $exitCode = Artisan::call('etoro:sync-portfolio', $parameters, $buffer);

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
    [$exitCode] = callSyncPortfolio($parameters);

    expect($exitCode)->toBe(2);
    Queue::assertNothingPushed();
})->with([
    'neither' => [[]],
    'both' => [['username' => 'trader_001', '--watched' => true]],
]);

it('rejects an unknown username without creating a trader or queueing', function () {
    [$exitCode, $output] = callSyncPortfolio(['username' => 'nobody_here']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('No stored trader')
        ->and(Trader::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues one job for a stored trader by default', function () {
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode] = callSyncPortfolio(['username' => 'trader_001']);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncTraderPortfolioJob::class, fn (SyncTraderPortfolioJob $job) => $job->trader->is($trader));
    Http::assertNothingSent();
});

it('queues jobs only for watched traders with --watched', function () {
    $watched = Trader::factory()->count(2)->create(['status' => TraderStatus::Watched]);
    Trader::factory()->create(['status' => TraderStatus::Candidate]);
    Trader::factory()->create(['status' => TraderStatus::Ignored]);

    [$exitCode] = callSyncPortfolio(['--watched' => true]);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncTraderPortfolioJob::class, 2);
    Queue::assertPushed(SyncTraderPortfolioJob::class, fn (SyncTraderPortfolioJob $job) => $watched->contains($job->trader));
});

it('does nothing and queues nothing when the integration is disabled', function () {
    config(['etoro.enabled' => false]);
    Trader::factory()->create(['status' => TraderStatus::Watched]);

    [$exitCode, $output] = callSyncPortfolio(['--watched' => true]);

    expect($exitCode)->toBe(0)->and($output)->toContain('disabled');
    Queue::assertNothingPushed();
});

it('runs synchronously with --now and prints only sanitized outcomes', function () {
    fakePortfolioCommandEndpoints();
    Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode, $output] = callSyncPortfolio(['username' => 'trader_001', '--now' => true]);
    [$secondExitCode, $secondOutput] = callSyncPortfolio(['username' => 'trader_001', '--now' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('completed')
        ->and($output)->toContain('new #')
        ->and($output)->toContain('16')
        ->and($output)->not->toContain('trader_001')
        ->and($output)->not->toContain('500001')
        ->and($output)->not->toContain('SYNA')
        ->and($secondExitCode)->toBe(0)
        ->and($secondOutput)->toContain('unchanged #')
        ->and($secondOutput)->toContain('skipped')
        ->and(PortfolioSnapshot::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('returns a failure exit code with --now when the sync does not complete', function () {
    Http::fake(['*' => Http::response([], 403)]);
    Trader::factory()->create(['username' => 'trader_001']);

    [$exitCode, $output] = callSyncPortfolio(['username' => 'trader_001', '--now' => true]);

    expect($exitCode)->toBe(1)->and($output)->toContain('not_visible');
    Http::assertSentCount(1);
});

it('is not scheduled yet (D-038)', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'etoro:sync-portfolio'));

    expect($event)->toBeNull();
});
