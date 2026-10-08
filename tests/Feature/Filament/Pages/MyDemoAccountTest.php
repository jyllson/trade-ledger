<?php

use App\Etoro\EtoroEnvironment;
use App\Filament\Pages\MyDemoAccount;
use App\Filament\Resources\Traders\TraderResource;
use App\Filament\Widgets\Account\DemoAccountHistoryChart;
use App\Jobs\SyncEtoroAccountJob;
use App\Models\AccountMirror;
use App\Models\AccountPosition;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['etoro.enabled' => true]);
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-08 10:00:00');
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Http::assertNothingSent();
    Carbon::setTestNow();
});

it('shows the DEMO notice and an empty state before the first sync', function () {
    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('DEMO account — virtual money')
        ->assertSee('No snapshot yet')
        ->assertSee('etoro:sync-account --demo --now');
});

it('shows the last failed attempt in the empty state', function () {
    ImportRun::factory()->create([
        'type' => 'account',
        'status' => ImportRunStatus::Failed,
        'metadata' => ['query' => ['environment' => 'demo']],
        'error_summary' => 'Account sync failed: not_authorized (the API key cannot read this account).',
        'finished_at' => now(),
    ]);

    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('not_authorized');
});

it('shows the latest snapshot, empty positions and copies for an empty account', function () {
    AccountSnapshot::factory()->create(['credit_cents' => 10_000_000, 'equity_cents' => 10_000_000, 'invested_cents' => 0]);

    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('$100,000.00')
        ->assertSee('2026-10-08 12:00 CEST')
        ->assertSee('No open positions.')
        ->assertSee('No active copies on the DEMO account.');
});

it('shows positions and copies, linking a copied trader that is stored', function () {
    $trader = Trader::factory()->create(['external_cid' => '5551234', 'username' => 'stored_trader']);
    $snapshot = AccountSnapshot::factory()->create([
        'credit_cents' => 500_025,
        'invested_cents' => 430_200,
        'equity_cents' => 917_575,
        'unrealized_pnl_cents' => 18_750,
        'position_count' => 1,
        'mirror_count' => 2,
        'mirror_position_count' => 1,
    ]);
    $linked = AccountMirror::factory()->for($snapshot, 'snapshot')->create(['mirror_index' => 0, 'parent_cid' => '5551234', 'parent_username' => 'stored_trader']);
    AccountMirror::factory()->for($snapshot, 'snapshot')->create(['mirror_index' => 1, 'parent_cid' => '7770001', 'parent_username' => 'unknown_trader']);
    $instrument = Instrument::factory()->create(['external_instrument_id' => '500001', 'symbol' => 'SYNA']);
    AccountPosition::factory()->for($snapshot, 'snapshot')->create(['position_index' => 0, 'instrument_id' => $instrument->id, 'external_instrument_id' => '500001', 'amount_cents' => 100_000, 'pnl_cents' => 12_040]);
    AccountPosition::factory()->for($snapshot, 'snapshot')->create(['position_index' => 1, 'account_mirror_id' => $linked->id, 'external_mirror_id' => $linked->external_mirror_id, 'is_buy' => false, 'pnl_cents' => -205]);

    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('$9,175.75')
        ->assertSee('$4,302.00')
        ->assertSee('SYNA')
        ->assertSee('$120.40')
        ->assertSee('-$2.05')
        ->assertSee('Copy of stored_trader')
        ->assertSee(TraderResource::getUrl('view', ['record' => $trader]), escape: false)
        ->assertSee('unknown_trader')
        ->assertSee('CID 7770001');
});

it('explains a missing equity instead of inventing one', function () {
    AccountSnapshot::factory()->create(['equity_cents' => null, 'equity_unavailable_reason' => 'position_pnl_missing']);

    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('eToro did not report P&amp;L for every position', escape: false);
});

it('ignores REAL snapshots on the demo page', function () {
    AccountSnapshot::factory()->create(['environment' => EtoroEnvironment::Real, 'credit_cents' => 123_456]);

    $this->get(MyDemoAccount::getUrl())
        ->assertOk()
        ->assertSee('No snapshot yet')
        ->assertDontSee('$1,234.56');
});

it('queues the demo account sync from the page action', function () {
    Queue::fake();

    Livewire::test(MyDemoAccount::class)
        ->callAction('syncDemoAccount')
        ->assertNotified('Demo account sync queued');

    Queue::assertPushed(SyncEtoroAccountJob::class, fn (SyncEtoroAccountJob $job) => $job->environment === EtoroEnvironment::Demo);
});

it('does not queue when the eToro integration is disabled', function () {
    Queue::fake();
    config(['etoro.enabled' => false]);

    Livewire::test(MyDemoAccount::class)
        ->callAction('syncDemoAccount')
        ->assertNotified('eToro integration is disabled — nothing was queued.');

    Queue::assertNothingPushed();
});

it('shows the history chart only with at least two demo snapshots', function () {
    AccountSnapshot::factory()->create();
    expect(DemoAccountHistoryChart::canView())->toBeFalse();

    AccountSnapshot::factory()->create(['captured_at' => now()->addDay(), 'equity_cents' => null]);
    expect(DemoAccountHistoryChart::canView())->toBeTrue();

    Livewire::test(DemoAccountHistoryChart::class)->assertOk();
});
