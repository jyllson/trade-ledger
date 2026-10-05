<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Application\Traders\BuildTraderPerformanceReport;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Resources\Traders\Widgets\TraderDrawdownChart;
use App\Filament\Resources\Traders\Widgets\TraderEquityChart;
use App\Filament\Resources\Traders\Widgets\TraderMonthlyReturns;
use App\Jobs\SyncTraderPerformanceJob;
use App\Models\PerformancePoint;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Stores the hand-calculated reference series (+10%, −20%, +25%, 0%, +5%)
 * as monthly points 2020-01 … 2020-05, synced after the last month ended.
 */
function storeReferenceMonthlyPerformance(Trader $trader): void
{
    foreach ([100_000_000, -200_000_000, 250_000_000, 0, 50_000_000] as $index => $ppb) {
        PerformancePoint::factory()->for($trader)->create([
            'granularity' => ReturnPeriodGranularity::Monthly,
            'period_start' => sprintf('2020-%02d-01', $index + 1),
            'gain_ppb' => $ppb,
            'synced_at' => '2020-06-10 00:00:00',
        ]);
    }

    $trader->forceFill([
        'performance_synced_at' => '2020-06-10 00:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
    ])->save();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
    ]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

it('renders hand-checkable monthly performance figures with explicit granularity — no HTTP call', function () {
    $trader = Trader::factory()->create();
    storeReferenceMonthlyPerformance($trader);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('Performance — monthly')
        ->assertSee('2020-01 → 2020-05')
        ->assertSee('+15.50 %')          // cumulative
        ->assertSee('+4.00 %')           // average month
        ->assertSee('+5.00 %')           // median month
        ->assertSee('3 / 1 / 1 (60.0 % positive)')
        ->assertSee('16.36 % / 56.66 %') // volatility monthly / annualized
        ->assertSee('Max MONTHLY drawdown (not intraday)')
        ->assertSee('20.00 % (peak 2020-01 → trough 2020-02, recovered 2020-03)')
        ->assertSee('Available')
        ->assertDontSee('Performance — daily');

    Http::assertNothingSent();
});

it('marks a partial first month and the month in progress', function () {
    Carbon::setTestNow('2020-03-15 12:00:00');
    $trader = Trader::factory()->create();

    foreach (['2020-01-09', '2020-02-01', '2020-03-01'] as $date) {
        PerformancePoint::factory()->for($trader)->create(['period_start' => $date, 'gain_ppb' => 10_000_000, 'synced_at' => now()]);
    }

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertSee('2020-01 → 2020-03 (partial first period, last period in progress)')
        ->assertSee('1 / 3');
});

it('shows the daily section and a daily drawdown chart when daily points exist', function () {
    $trader = Trader::factory()->create();
    storeReferenceMonthlyPerformance($trader);

    foreach ([-100_000_000, -100_000_000, 50_000_000] as $index => $ppb) {
        PerformancePoint::factory()->for($trader)->create([
            'granularity' => ReturnPeriodGranularity::Daily,
            'period_start' => sprintf('2020-06-%02d', $index + 1),
            'gain_ppb' => $ppb,
            'synced_at' => '2020-06-10 00:00:00',
        ]);
    }

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertSee('Performance — daily')
        ->assertSee('Max DAILY drawdown')
        ->assertSee('19.00 % (peak start → trough 2020-06-02, not recovered)');

    Livewire::test(TraderDrawdownChart::class, ['record' => $trader])
        ->assertSee('Drawdown — DAILY (stored window)');
});

it('hides the performance sections for a never-synced trader but keeps the sync status visible', function () {
    $trader = Trader::factory()->create();

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('Never synced')
        ->assertDontSee('Performance — monthly')
        ->assertDontSee('Performance — daily');

    Livewire::test(TraderMonthlyReturns::class, ['record' => $trader])
        ->assertSee('No monthly performance stored yet');
});

it('shows a private trader as opted out', function () {
    $trader = Trader::factory()->create(['performance_visibility' => PerformanceVisibility::Private]);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertSee('Private (opted out)');
});

it('queues a performance sync from the header action and notifies, without any HTTP call', function () {
    Queue::fake();
    $trader = Trader::factory()->create();

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->callAction('syncPerformance')
        ->assertNotified('Performance sync queued');

    Queue::assertPushed(SyncTraderPerformanceJob::class, fn (SyncTraderPerformanceJob $job) => $job->trader->is($trader));
    Http::assertNothingSent();
});

it('does not queue when the eToro integration is disabled', function () {
    Queue::fake();
    config(['etoro.enabled' => false]);
    $trader = Trader::factory()->create();

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->callAction('syncPerformance')
        ->assertNotified('eToro integration is disabled — nothing was queued.');

    Queue::assertNothingPushed();
});

it('renders the monthly returns table with signed, marked cells', function () {
    $trader = Trader::factory()->create();
    storeReferenceMonthlyPerformance($trader);

    Livewire::test(TraderMonthlyReturns::class, ['record' => $trader])
        ->assertSee('2020')
        ->assertSee('+10.0 %')
        ->assertSee('-20.0 %')
        ->assertSee('+25.0 %');
});

it('feeds the equity chart with the monthly equity index in percent', function () {
    $trader = Trader::factory()->create();
    storeReferenceMonthlyPerformance($trader);

    $chart = Livewire::test(TraderEquityChart::class, ['record' => $trader])->instance();
    $data = (fn () => $this->getData())->call($chart);

    expect($data['datasets'][0]['data'])->toBe([110.0, 88.0, 110.0, 110.0, 115.5])
        ->and($data['labels'])->toBe(['2020-01', '2020-02', '2020-03', '2020-04', '2020-05']);
});

it('memoizes the report per trader within one request', function () {
    $trader = Trader::factory()->create();
    storeReferenceMonthlyPerformance($trader);
    $builder = app(BuildTraderPerformanceReport::class);

    expect($builder->handle($trader))->toBe($builder->handle($trader));
});
