<?php

use App\Application\Traders\EnrichInstrumentMetadata;
use App\Application\Traders\InstrumentEnrichmentStatus;
use App\Application\Traders\SyncTraderPortfolio;
use App\Application\Traders\SyncTraderPortfolioStopReason;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use App\Models\PerformanceVisibility;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const PORTFOLIO_SYNC_LIVE_URL = 'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live';
const PORTFOLIO_SYNC_INSTRUMENTS_URL = 'https://public-api.etoro.com/api/v1/market-data/instruments*';
const PORTFOLIO_SYNC_TYPES_URL = 'https://public-api.etoro.com/api/v1/market-data/instrument-types';

/**
 * @return array<string, mixed>
 */
function portfolioSyncFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/Etoro/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fakes the live portfolio plus both market-data endpoints with the
 * synthetic fixtures, unless a test overrides a response.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakePortfolioSync(array $overrides = []): void
{
    Http::fake(array_merge([
        PORTFOLIO_SYNC_LIVE_URL => Http::response(portfolioSyncFixture('live-portfolio'), 200),
        PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response(portfolioSyncFixture('instrument-display-data'), 200),
        PORTFOLIO_SYNC_TYPES_URL => Http::response(portfolioSyncFixture('instrument-types'), 200),
    ], $overrides));
}

function portfolioSyncTrader(): Trader
{
    return Trader::factory()->create(['username' => 'trader_001']);
}

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

it('stores the snapshot, its positions in payload order, and exact ppb weights', function () {
    fakePortfolioSync();
    $trader = portfolioSyncTrader();

    $result = app(SyncTraderPortfolio::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::Completed)
        ->and($result->snapshotCreated)->toBeTrue()
        ->and(PortfolioSnapshot::count())->toBe(1);

    $snapshot = $result->snapshot->refresh();

    expect($snapshot->trader_id)->toBe($trader->id)
        ->and($snapshot->cash_weight_ppb)->toBe(0)
        ->and($snapshot->invested_weight_ppb)->toBe(1_000_000_000)
        ->and($snapshot->position_count)->toBe(16)
        ->and($snapshot->social_trades_count)->toBe(0)
        ->and($snapshot->source_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($snapshot->captured_at->equalTo($snapshot->last_confirmed_at))->toBeTrue();

    $positions = $snapshot->positions()->get();
    $first = $positions->first();

    expect($positions->pluck('external_position_id')->all())->toBe(array_map(fn (int $i): string => (string) (700000 + $i), range(1, 16)))
        ->and($positions->pluck('position_index')->all())->toBe(range(0, 15))
        ->and($first->external_instrument_id)->toBe('500001')
        ->and($first->instrument->external_instrument_id)->toBe('500001')
        ->and($first->weight_ppb)->toBe(1_000_000)
        ->and($first->opened_at->format('Y-m-d H:i:s'))->toBe('2012-01-10 09:15:00')
        ->and($first->is_buy)->toBeTrue()
        ->and($first->leverage)->toBe(1)
        ->and($first->take_profit_rate)->toBe('23.0000000000')
        ->and($first->stop_loss_rate)->toBe('18.0000000000')
        ->and($first->trailing_stop_loss)->toBeFalse()
        ->and($positions->last()->weight_ppb)->toBe(250_000_000)
        ->and($positions->whereNull('instrument_id'))->toHaveCount(0);

    $trader->refresh();

    expect($trader->portfolio_visibility)->toBe(PerformanceVisibility::Available)
        ->and($trader->portfolio_synced_at)->not->toBeNull();
});

it('upserts instrument metadata with the asset class from the instrument type catalogue', function () {
    fakePortfolioSync();

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Completed)
        ->and($result->enrichment->requestedCount)->toBe(6)
        ->and($result->enrichment->enrichedCount)->toBe(6)
        ->and(Instrument::count())->toBe(6)
        ->and(Instrument::whereNull('metadata_synced_at')->count())->toBe(0);

    $alpha = Instrument::where('external_instrument_id', '500001')->sole();
    $coin = Instrument::where('external_instrument_id', '500004')->sole();

    expect($alpha->symbol)->toBe('SYNA')
        ->and($alpha->name)->toBe('Synthetic Equity Alpha')
        ->and($alpha->instrument_type_id)->toBe(5)
        ->and($alpha->asset_class)->toBe('Stocks')
        ->and($alpha->exchange_id)->toBe(900)
        ->and($alpha->stocks_industry_id)->toBe(70)
        ->and($coin->asset_class)->toBe('Crypto')
        ->and($coin->stocks_industry_id)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://public-api.etoro.com/api/v1/market-data/instruments?instrumentIds='.urlencode('500001,500002,500003,500004,500005,500006'));
    Http::assertSentCount(3);
});

it('records one completed portfolio ImportRun with counts only', function () {
    fakePortfolioSync();
    $trader = portfolioSyncTrader();

    $result = app(SyncTraderPortfolio::class)->handle($trader);
    $run = $result->importRun;

    expect(ImportRun::count())->toBe(1)
        ->and($run->type)->toBe('portfolio')
        ->and($run->source)->toBe('etoro')
        ->and($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->request_count)->toBe(3)
        ->and($run->success_count)->toBe(1)
        ->and($run->failure_count)->toBe(0)
        ->and($run->error_summary)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->metadata)->toBe([
            'query' => ['trader_id' => $trader->id],
            'stop_reason' => 'completed',
            'snapshot_id' => $result->snapshot->id,
            'snapshot_created' => true,
            'position_count' => 16,
            'enrichment' => [
                'status' => 'completed',
                'requested' => 6,
                'enriched' => 6,
                'request_count' => 2,
                'failed_request_count' => 0,
            ],
        ])
        ->and(json_encode($run->metadata))->not->toContain('trader_001')
        ->and(json_encode($run->metadata))->not->toContain('SYNA');
});

it('is idempotent: two identical imports keep one snapshot and only refresh last_confirmed_at', function () {
    fakePortfolioSync();
    $trader = portfolioSyncTrader();

    $first = app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(2)->hours();
    $second = app(SyncTraderPortfolio::class)->handle($trader);

    expect(PortfolioSnapshot::count())->toBe(1)
        ->and(PortfolioPosition::count())->toBe(16)
        ->and($second->snapshotCreated)->toBeFalse()
        ->and($second->snapshot->id)->toBe($first->snapshot->id)
        ->and($second->snapshot->refresh()->last_confirmed_at->greaterThan($first->snapshot->captured_at))->toBeTrue()
        ->and($second->importRun->metadata['snapshot_created'])->toBeFalse()
        ->and($second->importRun->status)->toBe(ImportRunStatus::Completed)
        // Fresh metadata is not requested again.
        ->and($second->enrichment->status)->toBe(InstrumentEnrichmentStatus::Skipped)
        ->and($second->importRun->request_count)->toBe(1);

    Http::assertSentCount(4);
});

it('creates a new snapshot when the content changes, keeping the previous one', function () {
    $changed = portfolioSyncFixture('live-portfolio');
    $changed['positions'][0]['investmentPct'] = 0.2;
    $changed['realizedCreditPct'] = 0;
    $changed['positions'][1]['investmentPct'] = 0.1;

    Http::fake([
        PORTFOLIO_SYNC_LIVE_URL => Http::sequence()
            ->push(portfolioSyncFixture('live-portfolio'), 200)
            ->push($changed, 200)
            ->push(portfolioSyncFixture('live-portfolio'), 200),
        PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response(portfolioSyncFixture('instrument-display-data'), 200),
        PORTFOLIO_SYNC_TYPES_URL => Http::response(portfolioSyncFixture('instrument-types'), 200),
    ]);
    $trader = portfolioSyncTrader();

    $a = app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(1)->hours();
    $b = app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(1)->hours();
    $backToA = app(SyncTraderPortfolio::class)->handle($trader);

    expect($b->snapshotCreated)->toBeTrue()
        ->and($b->snapshot->source_hash)->not->toBe($a->snapshot->source_hash)
        // Same weights as A again, but observed after B: a new observation.
        ->and($backToA->snapshotCreated)->toBeTrue()
        ->and($backToA->snapshot->source_hash)->toBe($a->snapshot->source_hash)
        ->and(PortfolioSnapshot::count())->toBe(3)
        ->and(PortfolioPosition::count())->toBe(48)
        ->and($b->snapshot->positions()->first()->weight_ppb)->toBe(2_000_000)
        ->and($a->snapshot->positions()->first()->weight_ppb)->toBe(1_000_000)
        ->and($trader->latestPortfolioSnapshot->id)->toBe($backToA->snapshot->id);
});

it('treats position order, cash weight, and rates as part of the content', function (Closure $mutate) {
    $mutated = portfolioSyncFixture('live-portfolio');
    $mutate($mutated);

    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::sequence()->push(portfolioSyncFixture('live-portfolio'), 200)->push($mutated, 200)]);
    $trader = portfolioSyncTrader();

    app(SyncTraderPortfolio::class)->handle($trader);
    $second = app(SyncTraderPortfolio::class)->handle($trader);

    expect($second->snapshotCreated)->toBeTrue();
})->with([
    'order' => [function (array &$payload) {
        [$payload['positions'][0], $payload['positions'][1]] = [$payload['positions'][1], $payload['positions'][0]];
    }],
    'cash' => [function (array &$payload) {
        $payload['realizedCreditPct'] = 0.5;
    }],
    'stop loss' => [function (array &$payload) {
        $payload['positions'][3]['stopLossRate'] = 46.27;
    }],
]);

it('ignores fields that are not modelled, such as netProfit and openRate', function () {
    $noise = portfolioSyncFixture('live-portfolio');
    $noise['positions'][0]['netProfit'] = 999.99;
    $noise['positions'][0]['openRate'] = 1.23;
    $noise['unrealizedCreditPct'] = 4.2;

    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::sequence()->push(portfolioSyncFixture('live-portfolio'), 200)->push($noise, 200)]);
    $trader = portfolioSyncTrader();

    app(SyncTraderPortfolio::class)->handle($trader);
    $second = app(SyncTraderPortfolio::class)->handle($trader);

    expect($second->snapshotCreated)->toBeFalse()
        ->and(PortfolioSnapshot::count())->toBe(1);
});

it('stores an unknown cash weight as null, never as zero', function () {
    $payload = portfolioSyncFixture('live-portfolio');
    unset($payload['realizedCreditPct']);
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response($payload, 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->snapshot->refresh()->cash_weight_ppb)->toBeNull();
});

it('stores an empty portfolio as a snapshot without positions and without metadata requests', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response(['realizedCreditPct' => 100, 'positions' => [], 'socialTrades' => []], 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::Completed)
        ->and($result->snapshot->refresh()->position_count)->toBe(0)
        ->and($result->snapshot->invested_weight_ppb)->toBe(0)
        ->and($result->snapshot->cash_weight_ppb)->toBe(1_000_000_000)
        ->and($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Skipped)
        ->and(Instrument::count())->toBe(0);

    Http::assertSentCount(1);
});

it('keeps the snapshot when instrument metadata fails, marking the run partial', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response(['message' => 'boom'], 500)]);
    $trader = portfolioSyncTrader();

    $result = app(SyncTraderPortfolio::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::Completed)
        ->and(PortfolioSnapshot::count())->toBe(1)
        ->and(PortfolioPosition::count())->toBe(16)
        ->and(PortfolioPosition::whereNull('instrument_id')->count())->toBe(0)
        ->and(Instrument::count())->toBe(6)
        ->and(Instrument::whereNotNull('metadata_synced_at')->count())->toBe(0)
        ->and(Instrument::whereNotNull('symbol')->count())->toBe(0)
        ->and($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Failed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($result->importRun->success_count)->toBe(1)
        ->and($result->importRun->failure_count)->toBe(1)
        ->and($result->importRun->error_summary)->toBe('Portfolio snapshot stored; instrument metadata enrichment was incomplete.')
        ->and($result->importRun->metadata['enrichment']['status'])->toBe('failed')
        ->and($trader->refresh()->portfolio_visibility)->toBe(PerformanceVisibility::Available);

    // The instrument type catalogue is not requested when no display data arrived.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'instrument-types'));
});

it('retries enrichment on the next import after a metadata failure', function () {
    Http::fake([
        PORTFOLIO_SYNC_LIVE_URL => Http::response(portfolioSyncFixture('live-portfolio'), 200),
        PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::sequence()
            ->push([], 500)->push([], 500)->push([], 500)
            ->push(portfolioSyncFixture('instrument-display-data'), 200),
        PORTFOLIO_SYNC_TYPES_URL => Http::response(portfolioSyncFixture('instrument-types'), 200),
    ]);
    $trader = portfolioSyncTrader();

    app(SyncTraderPortfolio::class)->handle($trader);
    $second = app(SyncTraderPortfolio::class)->handle($trader);

    expect($second->snapshotCreated)->toBeFalse()
        ->and($second->enrichment->status)->toBe(InstrumentEnrichmentStatus::Completed)
        ->and(Instrument::whereNull('metadata_synced_at')->count())->toBe(0);
});

it('stores display metadata but leaves instruments unconfirmed when the type catalogue fails', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_TYPES_URL => Http::response(['message' => 'nope'], 403)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($result->enrichment->enrichedCount)->toBe(0)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($alpha->symbol)->toBe('SYNA')
        ->and($alpha->instrument_type_id)->toBe(5)
        ->and($alpha->asset_class)->toBeNull()
        ->and($alpha->metadata_synced_at)->toBeNull();
});

it('degrades, not fails, on a malformed metadata payload', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response(['unexpected' => true], 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::Completed)
        ->and($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Failed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and(PortfolioSnapshot::count())->toBe(1);
});

it('reports partial enrichment when the API omits a requested instrument', function () {
    $display = portfolioSyncFixture('instrument-display-data');
    array_pop($display['instrumentDisplayDatas']);
    fakePortfolioSync([PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response($display, 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($result->enrichment->enrichedCount)->toBe(5)
        ->and(Instrument::where('external_instrument_id', '500006')->value('metadata_synced_at'))->toBeNull();
});

it('leaves an instrument unconfirmed when the type catalogue lacks its type', function () {
    $types = portfolioSyncFixture('instrument-types');
    $types['instrumentTypes'] = array_values(array_filter(
        $types['instrumentTypes'],
        fn (array $type): bool => $type['instrumentTypeID'] !== 5,
    ));
    fakePortfolioSync([PORTFOLIO_SYNC_TYPES_URL => Http::response($types, 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($result->enrichment->enrichedCount)->toBeLessThan($result->enrichment->requestedCount)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($result->importRun->error_summary)->toBe('Portfolio snapshot stored; instrument metadata enrichment was incomplete.')
        ->and($alpha->symbol)->toBe('SYNA')
        ->and($alpha->instrument_type_id)->toBe(5)
        ->and($alpha->asset_class)->toBeNull()
        ->and($alpha->metadata_synced_at)->toBeNull();
});

it('leaves an instrument unconfirmed when its type id is missing or invalid', function (mixed $typeId) {
    $display = portfolioSyncFixture('instrument-display-data');

    if ($typeId === 'missing') {
        unset($display['instrumentDisplayDatas'][0]['instrumentTypeID']);
    } else {
        $display['instrumentDisplayDatas'][0]['instrumentTypeID'] = $typeId;
    }

    fakePortfolioSync([PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response($display, 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($result->enrichment->enrichedCount)->toBe($result->enrichment->requestedCount - 1)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($alpha->symbol)->toBe('SYNA')
        ->and($alpha->instrument_type_id)->toBeNull()
        ->and($alpha->asset_class)->toBeNull()
        ->and($alpha->metadata_synced_at)->toBeNull();
})->with([
    'missing' => ['missing'],
    'null' => [null],
    'zero' => [0],
    'string' => ['5'],
]);

it('requests an unconfirmed instrument again on the next import', function () {
    $types = portfolioSyncFixture('instrument-types');
    Http::fake([
        PORTFOLIO_SYNC_LIVE_URL => Http::response(portfolioSyncFixture('live-portfolio'), 200),
        PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::response(portfolioSyncFixture('instrument-display-data'), 200),
        PORTFOLIO_SYNC_TYPES_URL => Http::sequence()
            ->push(['instrumentTypes' => array_values(array_filter($types['instrumentTypes'], fn (array $type): bool => $type['instrumentTypeID'] !== 5))], 200)
            ->push($types, 200),
    ]);
    $trader = portfolioSyncTrader();

    app(SyncTraderPortfolio::class)->handle($trader);
    $second = app(SyncTraderPortfolio::class)->handle($trader);

    expect($second->enrichment->status)->toBe(InstrumentEnrichmentStatus::Completed)
        ->and($second->enrichment->requestedCount)->toBeGreaterThan(0)
        ->and(Instrument::whereNull('metadata_synced_at')->count())->toBe(0)
        ->and(Instrument::where('external_instrument_id', '500001')->value('asset_class'))->toBe('Stocks');
});

it('requests instrument metadata in batches of at most 100 ids', function () {
    $positions = [];

    foreach (range(1, 150) as $i) {
        $positions[] = ['positionId' => 800000 + $i, 'instrumentId' => 600000 + $i, 'investmentPct' => 0.5];
    }

    Http::fake([
        PORTFOLIO_SYNC_LIVE_URL => Http::response(['realizedCreditPct' => 25, 'positions' => $positions, 'socialTrades' => []], 200),
        PORTFOLIO_SYNC_INSTRUMENTS_URL => function (Request $request) {
            $ids = explode(',', $request->data()['instrumentIds']);

            return Http::response(['instrumentDisplayDatas' => array_map(fn (string $id): array => [
                'instrumentID' => (int) $id,
                'instrumentDisplayName' => "Synthetic {$id}",
                'symbolFull' => "S{$id}",
                'instrumentTypeID' => 5,
            ], $ids)], 200);
        },
        PORTFOLIO_SYNC_TYPES_URL => Http::response(portfolioSyncFixture('instrument-types'), 200),
    ]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    $batchSizes = Http::recorded(fn (Request $request) => str_contains($request->url(), 'market-data/instruments?'))
        ->map(fn (array $pair) => count(explode(',', $pair[0]->data()['instrumentIds'])))->values()
        ->all();

    expect($batchSizes)->toBe([EnrichInstrumentMetadata::BATCH_SIZE, 50])
        ->and($result->enrichment->status)->toBe(InstrumentEnrichmentStatus::Completed)
        ->and($result->enrichment->enrichedCount)->toBe(150)
        ->and($result->importRun->request_count)->toBe(4)
        ->and(Instrument::where('asset_class', 'Stocks')->count())->toBe(150);
});

it('refreshes instrument metadata older than the refresh window', function () {
    fakePortfolioSync();
    $trader = portfolioSyncTrader();

    app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(EnrichInstrumentMetadata::REFRESH_AFTER_DAYS + 1)->days();
    $later = app(SyncTraderPortfolio::class)->handle($trader);

    expect($later->enrichment->status)->toBe(InstrumentEnrichmentStatus::Completed)
        ->and($later->enrichment->requestedCount)->toBe(6);
});

it('clears the previous classification when a refreshed instrument changes to an unresolved type', function () {
    $display = portfolioSyncFixture('instrument-display-data');
    $display['instrumentDisplayDatas'][0]['instrumentTypeID'] = 6;
    $types = portfolioSyncFixture('instrument-types');
    fakePortfolioSync([
        PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::sequence()
            ->push(portfolioSyncFixture('instrument-display-data'), 200)
            ->push($display, 200),
        PORTFOLIO_SYNC_TYPES_URL => Http::sequence()
            ->push($types, 200)
            ->push(['instrumentTypes' => array_values(array_filter($types['instrumentTypes'], fn (array $type): bool => $type['instrumentTypeID'] !== 6))], 200),
    ]);
    $trader = portfolioSyncTrader();
    app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(EnrichInstrumentMetadata::REFRESH_AFTER_DAYS + 1)->days();

    $later = app(SyncTraderPortfolio::class)->handle($trader);
    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($later->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($later->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($alpha->instrument_type_id)->toBe(6)
        ->and($alpha->asset_class)->toBeNull()
        ->and($alpha->metadata_synced_at)->toBeNull();
});

it('keeps the previous classification when a refreshed instrument type cannot be resolved', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_TYPES_URL => Http::sequence()
        ->push(portfolioSyncFixture('instrument-types'), 200)
        ->push(['message' => 'boom'], 500)->push(['message' => 'boom'], 500)->push(['message' => 'boom'], 500)]);
    $trader = portfolioSyncTrader();
    app(SyncTraderPortfolio::class)->handle($trader);
    $firstSyncedAt = Instrument::where('external_instrument_id', '500001')->sole()->metadata_synced_at;
    $this->travel(EnrichInstrumentMetadata::REFRESH_AFTER_DAYS + 1)->days();

    $later = app(SyncTraderPortfolio::class)->handle($trader);
    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($later->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($later->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($alpha->instrument_type_id)->toBe(5)
        ->and($alpha->asset_class)->toBe('Stocks')
        ->and($alpha->metadata_synced_at->equalTo($firstSyncedAt))->toBeTrue();
});

it('clears the previous classification when a refreshed instrument loses its type id', function () {
    $display = portfolioSyncFixture('instrument-display-data');
    unset($display['instrumentDisplayDatas'][0]['instrumentTypeID']);
    fakePortfolioSync([PORTFOLIO_SYNC_INSTRUMENTS_URL => Http::sequence()
        ->push(portfolioSyncFixture('instrument-display-data'), 200)
        ->push($display, 200)]);
    $trader = portfolioSyncTrader();
    app(SyncTraderPortfolio::class)->handle($trader);
    $this->travel(EnrichInstrumentMetadata::REFRESH_AFTER_DAYS + 1)->days();

    $later = app(SyncTraderPortfolio::class)->handle($trader);
    $alpha = Instrument::where('external_instrument_id', '500001')->sole();

    expect($later->enrichment->status)->toBe(InstrumentEnrichmentStatus::Partial)
        ->and($alpha->instrument_type_id)->toBeNull()
        ->and($alpha->asset_class)->toBeNull()
        ->and($alpha->metadata_synced_at)->toBeNull();
});

it('marks a private portfolio, keeps stored snapshots, and does not retry', function () {
    PortfolioSnapshot::factory()->create(['trader_id' => ($trader = portfolioSyncTrader())->id]);
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response(['message' => 'opted out'], 403)]);

    $result = app(SyncTraderPortfolio::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::NotVisible)
        ->and($result->stopReason->isRetryable())->toBeFalse()
        ->and($result->snapshot)->toBeNull()
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->failure_count)->toBe(1)
        ->and($result->importRun->error_summary)->toBe('Trader portfolio is not visible (trader opted out of portfolio exposure).')
        ->and($trader->refresh()->portfolio_visibility)->toBe(PerformanceVisibility::Private)
        ->and(PortfolioSnapshot::count())->toBe(1);

    Http::assertSentCount(1);
});

it('marks a missing trader as not found', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response([], 404)]);
    $trader = portfolioSyncTrader();

    $result = app(SyncTraderPortfolio::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::NotFound)
        ->and($trader->refresh()->portfolio_visibility)->toBe(PerformanceVisibility::NotFound)
        ->and($result->importRun->error_summary)->toBe('Trader portfolio sync failed: not_found.');
});

it('reports a rate-limited request as retryable with Retry-After and stores nothing', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response([], 429, ['Retry-After' => '37'])]);
    $trader = portfolioSyncTrader();

    $result = app(SyncTraderPortfolio::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::TemporarilyUnavailable)
        ->and($result->retryAfterSeconds)->toBe(37)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and(PortfolioSnapshot::count())->toBe(0)
        ->and(Instrument::count())->toBe(0)
        ->and($trader->refresh()->portfolio_visibility)->toBeNull();
});

it('fails closed on a malformed portfolio payload without storing anything', function () {
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response(['positions' => [['positionId' => 1]], 'socialTrades' => []], 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::MappingFailed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->request_count)->toBe(1)
        ->and($result->importRun->metadata['stop_reason'])->toBe('mapping_failed')
        ->and(PortfolioSnapshot::count())->toBe(0)
        ->and(Instrument::count())->toBe(0);

    Http::assertSentCount(1);
});

it('stops before any request when credentials are missing', function () {
    config(['etoro.api_key' => null]);
    Http::fake();

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->stopReason)->toBe(SyncTraderPortfolioStopReason::ConfigurationError)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->request_count)->toBe(0);

    Http::assertNothingSent();
});

it('keeps duplicate position ids as received, in order', function () {
    $payload = portfolioSyncFixture('live-portfolio');
    $payload['positions'][1]['positionId'] = $payload['positions'][0]['positionId'];
    fakePortfolioSync([PORTFOLIO_SYNC_LIVE_URL => Http::response($payload, 200)]);

    $result = app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    expect($result->snapshot->positions()->limit(2)->pluck('external_position_id')->all())->toBe(['700001', '700001'])
        ->and($result->snapshot->position_count)->toBe(16);
});

it('only ever sends GET requests', function () {
    fakePortfolioSync();

    app(SyncTraderPortfolio::class)->handle(portfolioSyncTrader());

    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});
