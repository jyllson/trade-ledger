<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Application\Traders\SyncTraderPerformance;
use App\Application\Traders\SyncTraderPerformanceStopReason;
use App\Etoro\GainGranularity;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformancePoint;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const GAIN_HISTORY_URL = 'https://public-api.etoro.com/api/v2/portfolios/*/gain/monthly*';

/**
 * @return array<string, mixed>
 */
function gainHistoryPayload(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-monthly.json')), true, flags: JSON_THROW_ON_ERROR);
}

function performanceTrader(): Trader
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

it('stores the full monthly series with exact ppb gains and marks the trader available', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response(gainHistoryPayload(), 200)]);
    $trader = performanceTrader();

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPerformanceStopReason::Completed)
        ->and($result->storedPointCount)->toBe(26)
        ->and($trader->performancePoints()->count())->toBe(26);

    $first = $trader->performancePoints()->orderBy('period_start')->first();

    expect($first->period_start)->toBe('2011-03-09')
        ->and($first->gain_ppb)->toBe(12_500_000)
        ->and($first->granularity)->toBe(ReturnPeriodGranularity::Monthly)
        ->and($first->source)->toBe(PerformancePoint::SOURCE_ETORO_V2_GAIN);

    $trader->refresh();

    expect($trader->performance_visibility)->toBe(PerformanceVisibility::Available)
        ->and($trader->performance_synced_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://public-api.etoro.com/api/v2/portfolios/trader_001/gain/monthly?count=1000');
});

it('records exactly one completed performance ImportRun without the username in metadata', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response(gainHistoryPayload(), 200)]);
    $trader = performanceTrader();

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect(ImportRun::count())->toBe(1)
        ->and($result->importRun->type)->toBe('performance')
        ->and($result->importRun->source)->toBe('etoro')
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->request_count)->toBe(1)
        ->and($result->importRun->success_count)->toBe(1)
        ->and($result->importRun->metadata)->toBe([
            'query' => ['trader_id' => $trader->id, 'granularity' => 'monthly', 'count' => 1000],
            'stop_reason' => 'completed',
            'stored_point_count' => 26,
        ])
        ->and(json_encode($result->importRun->metadata))->not->toContain('trader_001');
});

it('is idempotent: re-running the same response leaves the same rows', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response(gainHistoryPayload(), 200)]);
    $trader = performanceTrader();

    app(SyncTraderPerformance::class)->handle($trader);
    $idsBefore = PerformancePoint::orderBy('id')->pluck('id')->all();
    app(SyncTraderPerformance::class)->handle($trader);

    expect(PerformancePoint::orderBy('id')->pluck('id')->all())->toBe($idsBefore)
        ->and(PerformancePoint::count())->toBe(26);
});

it('replaces the stored series within the returned range: updates changed periods and removes periods no longer returned', function () {
    $trader = performanceTrader();
    $first = gainHistoryPayload();
    $second = gainHistoryPayload();
    array_splice($second['gains'], 10, 1);
    $second['gains'][24]['gain'] = 0.0321;

    Http::fakeSequence(GAIN_HISTORY_URL)->push($first, 200)->push($second, 200);

    app(SyncTraderPerformance::class)->handle($trader);
    app(SyncTraderPerformance::class)->handle($trader);

    expect(PerformancePoint::count())->toBe(25)
        ->and(PerformancePoint::where('period_start', $first['gains'][10]['date'])->exists())->toBeFalse()
        ->and(PerformancePoint::where('period_start', '2013-04-01')->value('gain_ppb'))->toBe(32_100_000);
});

it('keeps stored periods outside the returned range, so a sliding daily window never loses older days', function () {
    $trader = performanceTrader();
    $daily = json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-daily.json')), true, flags: JSON_THROW_ON_ERROR);
    $later = $daily;
    $later['gains'] = array_slice($daily['gains'], 7);

    Http::fakeSequence('https://public-api.etoro.com/api/v2/portfolios/*/gain/daily*')->push($daily, 200)->push($later, 200);

    app(SyncTraderPerformance::class)->handle($trader, GainGranularity::Daily);
    $result = app(SyncTraderPerformance::class)->handle($trader, GainGranularity::Daily);

    expect($result->storedPointCount)->toBe(7)
        ->and($trader->performancePoints()->where('granularity', 'daily')->count())->toBe(14)
        ->and($result->importRun->metadata['query']['granularity'])->toBe('daily');
});

it('stores monthly and daily series side by side without interfering', function () {
    $trader = performanceTrader();
    Http::fake([
        GAIN_HISTORY_URL => Http::response(gainHistoryPayload(), 200),
        'https://public-api.etoro.com/api/v2/portfolios/*/gain/daily*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-daily.json')), true), 200),
    ]);

    app(SyncTraderPerformance::class)->handle($trader, GainGranularity::Monthly);
    app(SyncTraderPerformance::class)->handle($trader, GainGranularity::Daily);
    app(SyncTraderPerformance::class)->handle($trader, GainGranularity::Monthly);

    expect($trader->performancePoints()->where('granularity', 'monthly')->count())->toBe(26)
        ->and($trader->performancePoints()->where('granularity', 'daily')->count())->toBe(14);
});

it('never touches another trader’s stored series', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response(gainHistoryPayload(), 200)]);
    $other = PerformancePoint::factory()->create(['period_start' => '2011-03-09']);

    app(SyncTraderPerformance::class)->handle(performanceTrader());

    expect(PerformancePoint::whereKey($other->id)->exists())->toBeTrue();
});

it('treats a 403 as a private trader: keeps stored history and records a sanitized failure', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response(['success' => false, 'error' => ['message' => 'SENTINEL_UPSTREAM_MESSAGE']], 403)]);
    $trader = performanceTrader();
    PerformancePoint::factory()->for($trader)->create(['period_start' => '2010-01-01']);

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPerformanceStopReason::NotVisible)
        ->and($trader->refresh()->performance_visibility)->toBe(PerformanceVisibility::Private)
        ->and($trader->performance_synced_at)->toBeNull()
        ->and($trader->performancePoints()->count())->toBe(1)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->not->toContain('SENTINEL_UPSTREAM_MESSAGE')
        ->and($result->retryAfterSeconds)->toBeNull();
});

it('marks a 404 as not found', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response([], 404)]);
    $trader = performanceTrader();

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPerformanceStopReason::NotFound)
        ->and($trader->refresh()->performance_visibility)->toBe(PerformanceVisibility::NotFound);
});

it('reports a 429 as retryable with the Retry-After hint and leaves visibility unchanged', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response([], 429, ['Retry-After' => '42'])]);
    $trader = performanceTrader();

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect($result->stopReason)->toBe(SyncTraderPerformanceStopReason::TemporarilyUnavailable)
        ->and($result->stopReason->isRetryable())->toBeTrue()
        ->and($result->retryAfterSeconds)->toBe(42)
        ->and($trader->refresh()->performance_visibility)->toBeNull();
});

it('fails closed without storing anything when the response is for another granularity or user', function (array $override, SyncTraderPerformanceStopReason $expected) {
    Http::fake([GAIN_HISTORY_URL => Http::response(array_merge(gainHistoryPayload(), $override), 200)]);
    $trader = performanceTrader();

    $result = app(SyncTraderPerformance::class)->handle($trader);

    expect($result->stopReason)->toBe($expected)
        ->and(PerformancePoint::count())->toBe(0)
        ->and($trader->refresh()->performance_synced_at)->toBeNull();
})->with([
    'granularity mismatch' => [['granularity' => 'daily'], SyncTraderPerformanceStopReason::MappingFailed],
    'username mismatch' => [['username' => 'someone_else'], SyncTraderPerformanceStopReason::IdentityMismatch],
]);

it('records a configuration error without any HTTP request when the integration is disabled', function () {
    config(['etoro.enabled' => false]);
    Http::fake();

    $result = app(SyncTraderPerformance::class)->handle(performanceTrader());

    expect($result->stopReason)->toBe(SyncTraderPerformanceStopReason::ConfigurationError)
        ->and($result->importRun->request_count)->toBe(0);
    Http::assertNothingSent();
});

it('never stores credentials in the ImportRun', function () {
    Http::fake([GAIN_HISTORY_URL => Http::response([], 500)]);

    $result = app(SyncTraderPerformance::class)->handle(performanceTrader());
    $serialized = json_encode($result->importRun->toArray());

    expect($serialized)->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});
