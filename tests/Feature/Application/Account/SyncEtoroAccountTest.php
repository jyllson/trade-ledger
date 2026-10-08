<?php

use App\Application\Account\AccountSyncNotEnabled;
use App\Application\Account\SyncEtoroAccount;
use App\Application\Account\SyncEtoroAccountStopReason;
use App\Etoro\EtoroEnvironment;
use App\Models\AccountMirror;
use App\Models\AccountPosition;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use App\Models\Trader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/AccountFixtures.php';

beforeEach(function () {
    configureEtoroForAccountTests();
    Http::preventStrayRequests();
    Sleep::fake();
    Carbon::setTestNow('2026-10-08 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stores a demo snapshot with positions, copies and the guide valuation', function () {
    fakeDemoAccountPnl();
    $trader = Trader::factory()->create(['external_cid' => '5551234', 'username' => 'trader_001']);

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe(SyncEtoroAccountStopReason::Completed)
        ->and($result->snapshotCreated)->toBeTrue()
        ->and($result->importRun->type)->toBe('account')
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->metadata)->toMatchArray([
            'query' => ['environment' => 'demo'],
            'stop_reason' => 'completed',
            'snapshot_created' => true,
            'position_count' => 2,
            'mirror_count' => 1,
            'unmodeled_fields' => ['someFutureField'],
        ]);

    $snapshot = AccountSnapshot::sole();
    expect($snapshot->environment)->toBe(EtoroEnvironment::Demo)
        ->and($snapshot->credit_cents)->toBe(500_025)
        ->and($snapshot->unrealized_pnl_cents)->toBe(18_750)
        ->and($snapshot->invested_cents)->toBe(430_200)
        ->and($snapshot->equity_cents)->toBe(917_575)
        ->and($snapshot->equity_unavailable_reason)->toBeNull()
        ->and($snapshot->position_count)->toBe(2)
        ->and($snapshot->mirror_count)->toBe(1)
        ->and($snapshot->mirror_position_count)->toBe(2)
        ->and($snapshot->pending_order_count)->toBe(3)
        ->and($snapshot->captured_at->toDateTimeString())->toBe('2026-10-08 10:00:00');

    $mirror = AccountMirror::sole();
    expect($mirror->external_mirror_id)->toBe('7001')
        ->and($mirror->parent_cid)->toBe('5551234')
        ->and($mirror->invested_cents)->toBe(220_000)
        ->and($mirror->unrealized_pnl_cents)->toBe(7_325)
        ->and($mirror->closed_positions_net_profit_cents)->toBe(5_000)
        ->and($mirror->position_count)->toBe(2)
        ->and($mirror->trader->is($trader))->toBeTrue();

    $positions = AccountPosition::orderBy('position_index')->get();
    expect($positions)->toHaveCount(4)
        ->and($positions->pluck('external_position_id')->all())->toBe(['100001', '100002', '200001', '200002'])
        ->and($positions->pluck('account_mirror_id')->all())->toBe([null, null, $mirror->id, $mirror->id])
        ->and($positions[0]->units)->toBe('5.4839000000')
        ->and($positions[1]->is_buy)->toBeFalse()
        ->and($positions[1]->pnl_cents)->toBe(-2_015)
        ->and($positions[2]->external_mirror_id)->toBe('7001')
        ->and($positions->every(fn (AccountPosition $position) => $position->instrument_id !== null))->toBeTrue()
        ->and(Instrument::where('external_instrument_id', '500001')->value('symbol'))->toBe('SYNA');

    Http::assertSent(fn (Request $request) => $request->url() === ACCOUNT_DEMO_PNL_URL && $request->method() === 'GET');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/real/'));
});

it('is idempotent: identical content only moves last_confirmed_at', function () {
    fakeDemoAccountPnl();
    app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    Carbon::setTestNow('2026-10-09 10:00:00');
    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe(SyncEtoroAccountStopReason::Completed)
        ->and($result->snapshotCreated)->toBeFalse()
        ->and(AccountSnapshot::count())->toBe(1)
        ->and(AccountPosition::count())->toBe(4)
        ->and(AccountMirror::count())->toBe(1)
        ->and(AccountSnapshot::sole()->captured_at->toDateTimeString())->toBe('2026-10-08 10:00:00')
        ->and(AccountSnapshot::sole()->last_confirmed_at->toDateTimeString())->toBe('2026-10-09 10:00:00')
        ->and(ImportRun::where('type', 'account')->count())->toBe(2);
});

it('stores a new snapshot when the account changed', function () {
    $changed = accountPnlPayload('account-pnl-empty.json');
    $changed['clientPortfolio']['credit'] = 98765.44;
    Http::fake([ACCOUNT_DEMO_PNL_URL => Http::sequence()
        ->push(accountPnlPayload('account-pnl-empty.json'))
        ->push($changed)]);

    app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);
    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->snapshotCreated)->toBeTrue()
        ->and(AccountSnapshot::count())->toBe(2)
        ->and(AccountSnapshot::orderByDesc('id')->first()->credit_cents)->toBe(9_876_544);
});

it('stores the empty demo account with equity equal to credit and no metadata requests', function () {
    fakeDemoAccountPnl(accountPnlPayload('account-pnl-empty.json'));

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe(SyncEtoroAccountStopReason::Completed)
        ->and($result->snapshot->equity_cents)->toBe(9_876_543)
        ->and($result->snapshot->invested_cents)->toBe(0)
        ->and($result->importRun->request_count)->toBe(1);
    Http::assertSentCount(1);
});

it('refuses the REAL account in code: no request, no import run, no snapshot', function () {
    fakeDemoAccountPnl();

    expect(fn () => app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Real))
        ->toThrow(AccountSyncNotEnabled::class, 'disabled in code');

    Http::assertNothingSent();
    expect(ImportRun::count())->toBe(0)
        ->and(AccountSnapshot::count())->toBe(0);
});

it('records mapping_failed with a structural diagnosis and stores nothing', function () {
    $payload = accountPnlPayload();
    unset($payload['clientPortfolio']['mirrors'][0]['parentCID']);
    fakeDemoAccountPnl($payload);

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe(SyncEtoroAccountStopReason::MappingFailed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->toBe('Account sync failed: mapping_failed (missing_required_field at clientPortfolio.mirrors[0].parentCID).')
        ->and($result->importRun->metadata['mapping_error'])->toBe([
            'mapper' => 'AccountPnlMapper',
            'field_path' => 'clientPortfolio.mirrors[0].parentCID',
            'reason' => 'missing_required_field',
            'expected_type' => null,
            'actual_type' => null,
        ])
        ->and(AccountSnapshot::count())->toBe(0);
});

it('never stores the raw payload or money values in the import run', function () {
    fakeDemoAccountPnl();

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    $stored = json_encode([$result->importRun->metadata, $result->importRun->error_summary]);
    expect($stored)->not->toContain('5000.25')
        ->and($stored)->not->toContain('trader_001')
        ->and($stored)->not->toContain('9000001')
        ->and($stored)->not->toContain('test-api-key-value');
});

it('maps HTTP failures to stop reasons', function (int $status, SyncEtoroAccountStopReason $expected, ?int $retryAfter) {
    fakeDemoAccountPnl(['error' => 'nope'], $status, ['Retry-After' => '42']);

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe($expected)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and(AccountSnapshot::count())->toBe(0);
})->with([
    '401' => [401, SyncEtoroAccountStopReason::NotAuthorized, null],
    '403' => [403, SyncEtoroAccountStopReason::NotAuthorized, null],
    '404' => [404, SyncEtoroAccountStopReason::RequestFailed, null],
    '429' => [429, SyncEtoroAccountStopReason::TemporarilyUnavailable, 42],
]);

it('stops on a disabled integration without sending anything', function () {
    config(['etoro.enabled' => false]);
    fakeDemoAccountPnl();

    $result = app(SyncEtoroAccount::class)->handle(EtoroEnvironment::Demo);

    expect($result->stopReason)->toBe(SyncEtoroAccountStopReason::ConfigurationError);
    Http::assertNothingSent();
});
