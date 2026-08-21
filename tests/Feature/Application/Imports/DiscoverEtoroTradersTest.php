<?php

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\DiscoverEtoroTradersRequest;
use App\Application\Imports\DiscoverEtoroTradersStopReason;
use App\Application\Imports\ImportRankingPage;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\RankingsMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const DISCOVERY_RANKINGS_URL = 'https://public-api.etoro.com/api/v2/portfolios/rankings*';

function discoverTradersUseCase(): DiscoverEtoroTraders
{
    return new DiscoverEtoroTraders(new EtoroClient(app(Factory::class)), new RankingsMapper, new ImportRankingPage);
}

/**
 * @return array<string, mixed>
 */
function discoverTradersEntry(string $cid, string $username): array
{
    return [
        'cid' => $cid,
        'username' => $username,
        'type' => 'trader',
        'subType' => 'pi-certified',
        'copiers' => 100,
    ];
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function discoverTradersRankingsPayload(array $entries, int $page, int $pageSize, int $totalItems, bool $hasNext): array
{
    return [
        'results' => $entries,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'totalItems' => $totalItems,
            'hasNext' => $hasNext,
        ],
    ];
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
});

// --- A. Natural completion, single page ---------------------------------

it('completes naturally on a single page with zero rejections', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::NaturalCompletion)
        ->and($result->pagesFetched)->toBe(1)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->type)->toBe('rankings_discovery')
        ->and($result->importRun->source)->toBe('etoro')
        ->and($result->importRun->request_count)->toBe(1)
        ->and($result->importRun->success_count)->toBe(1)
        ->and($result->importRun->failure_count)->toBe(0)
        ->and($result->importRun->error_summary)->toBeNull();

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();

    expect(Trader::query()->count())->toBe(1);

    $childRun = ImportRun::query()->where('type', 'rankings')->firstOrFail();
    expect($childRun->parent_import_run_id)->toBe($result->importRun->id);
});

// --- B. Natural completion across multiple pages -------------------------

it('fetches exact sequential pages with pageSize=20 and sleeps 2s only between pages', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::sequence()
            ->push(discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 3, hasNext: true), 200)
            ->push(discoverTradersRankingsPayload([discoverTradersEntry('1002', 'trader_b')], page: 2, pageSize: 20, totalItems: 3, hasNext: true), 200)
            ->push(discoverTradersRankingsPayload([discoverTradersEntry('1003', 'trader_c')], page: 3, pageSize: 20, totalItems: 3, hasNext: false), 200),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::NaturalCompletion)
        ->and($result->pagesFetched)->toBe(3)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->request_count)->toBe(3)
        ->and($result->importRun->success_count)->toBe(3)
        ->and(count($result->childImportRunIds))->toBe(3);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pageSize=20'));

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(2)->seconds(),
    ]);
    Sleep::assertSleptTimes(2);

    expect(Trader::query()->count())->toBe(3);
});

it('starts from the configured start page', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1005', 'trader_e')], page: 5, pageSize: 20, totalItems: 5, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 5, maxPages: 1);
    discoverTradersUseCase()->handle($request);

    Http::assertSent(fn (Request $req): bool => str_contains($req->url(), 'page=5'));
});

// --- C. Page limit reached ------------------------------------------------

it('stops with page_limit_reached when hasNext is still true after maxPages', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 100, hasNext: true),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 1);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::PageLimitReached)
        ->and($result->pagesFetched)->toBe(1)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($result->importRun->error_summary)->toContain('page limit');

    Http::assertSentCount(1);
});

// --- D. Pagination mismatch (page and pageSize both checked) -------------

it('stops with pagination_mismatch and writes nothing for that page when the server page differs from the request', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 2, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::PaginationMismatch)
        ->and($result->pagesFetched)->toBe(0)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed);

    expect(Trader::query()->count())->toBe(0);
    expect(ImportRun::query()->where('type', 'rankings')->count())->toBe(0);
});

it('stops with pagination_mismatch when the server pageSize differs from the fixed 20', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 10, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::PaginationMismatch)
        ->and($result->pagesFetched)->toBe(0);

    expect(Trader::query()->count())->toBe(0);
});

it('does not use totalItems to derive any expected page count', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 999999, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::NaturalCompletion)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed);

    Http::assertSentCount(1);
});

// --- E. Typed failures, before and after a successful page ----------------

it('marks the aggregate Failed when a request failure happens before any successful page, with request_count matching the physical HTTP attempts including retries', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(['error' => 'boom'], 500),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::RequestFailed)
        ->and($result->pagesFetched)->toBe(0)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->toContain('request_failed')
        ->and($result->importRun->error_summary)->not->toContain('boom');

    // EtoroClient retries a persistent 5xx up to its own MAX_ATTEMPTS (3)
    // internal physical attempts before finally throwing
    // EtoroRequestException — request_count must equal that real physical
    // attempt count, matching the actual number of HTTP calls sent, never a
    // flat "1 per logical page" count.
    Http::assertSentCount(3);
    expect($result->importRun->request_count)->toBe(3);
});

it('recovers on the final physical retry attempt within a single logical page, with request_count reflecting all attempts', function (): void {
    Sleep::fake();

    $callCount = 0;
    Http::fake([
        DISCOVERY_RANKINGS_URL => function () use (&$callCount) {
            $callCount++;

            if ($callCount < 3) {
                return Http::response(['error' => 'boom'], 503);
            }

            return Http::response(discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 1, hasNext: false), 200);
        },
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::NaturalCompletion)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->pagesFetched)->toBe(1);

    Http::assertSentCount(3);
    expect($result->importRun->request_count)->toBe(3);
});

it('marks the aggregate Partial when a request failure happens after one successful page', function (): void {
    Sleep::fake();

    // EtoroClient retries a 5xx up to 3 attempts internally, so a fixed
    // Http::sequence() would run out mid-retry; a call-count callback
    // reliably returns the first-page success once and a failure for every
    // attempt after that, regardless of how many retries occur.
    $callCount = 0;
    Http::fake([
        DISCOVERY_RANKINGS_URL => function () use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response(discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 2, hasNext: true), 200);
            }

            return Http::response(['error' => 'boom'], 500);
        },
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::RequestFailed)
        ->and($result->pagesFetched)->toBe(1)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($result->importRun->success_count)->toBe(1);

    expect(Trader::query()->count())->toBe(1);
});

it('marks the aggregate Failed for a configuration error before any HTTP call, with request_count zero', function (): void {
    config(['etoro.enabled' => false]);
    Sleep::fake();

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::ConfigurationError)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->request_count)->toBe(0);

    Http::assertNothingSent();
});

it('marks the aggregate Failed for a 2xx unexpected response shape, with request_count matching physical attempts including retries', function (): void {
    Sleep::fake();

    $callCount = 0;
    Http::fake([
        DISCOVERY_RANKINGS_URL => function () use (&$callCount) {
            $callCount++;

            if ($callCount < 2) {
                return Http::response(['error' => 'boom'], 503);
            }

            return Http::response('not-json-array', 200);
        },
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::UnexpectedResponse)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed);

    Http::assertSentCount(2);
    expect($result->importRun->request_count)->toBe(2);
});

it('marks the aggregate Failed for a mapping failure', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(['pagination' => ['page' => 1, 'pageSize' => 20, 'totalItems' => 0, 'hasNext' => false]], 200),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->stopReason)->toBe(DiscoverEtoroTradersStopReason::MappingFailed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed);
});

// --- F. Controlled child rejections aggregated -----------------------------

it('aggregates controlled row-level rejections from a child page into the parent counts', function (): void {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'trader_a']);

    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([
                discoverTradersEntry('1001', 'trader_a'),
                discoverTradersEntry('1002', 'trader_b'),
            ], page: 1, pageSize: 20, totalItems: 2, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $result = discoverTradersUseCase()->handle($request);

    expect($result->importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($result->importRun->success_count)->toBe(1)
        ->and($result->importRun->failure_count)->toBe(1)
        ->and($result->importRun->error_summary)->toContain('controlled trader identity conflict')
        ->and($result->importRun->error_summary)->not->toContain('1001')
        ->and($result->importRun->error_summary)->not->toContain('trader_a');
});

// --- G. Idempotent rerun ---------------------------------------------------

it('creates no duplicate traders on a repeated discovery run over the same data', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    discoverTradersUseCase()->handle($request);
    discoverTradersUseCase()->handle($request);

    expect(Trader::query()->count())->toBe(1);
    expect(ImportRun::query()->where('type', 'rankings_discovery')->count())->toBe(2);
});

// --- H. Unexpected Throwable: best-effort finalize, then rethrow -----------

it('(a) rethrows the ORIGINAL exception when the best-effort aggregate finalize save itself succeeds', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    $hasThrown = false;

    ImportRun::saving(function (ImportRun $importRun) use (&$hasThrown): void {
        if (! $hasThrown && $importRun->type === 'rankings' && $importRun->status !== ImportRunStatus::Running) {
            $hasThrown = true;

            throw new RuntimeException('Simulated child ImportRun finalization failure for test purposes.');
        }
    });

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $thrown = null;

    try {
        try {
            discoverTradersUseCase()->handle($request);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    // The aggregate's own finalize() save was NOT targeted by the listener
    // above, so it succeeds — proving the rethrown exception really is the
    // original one, not a substitute produced by the recovery path itself.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Simulated child ImportRun finalization failure for test purposes.');

    $aggregateRun = ImportRun::query()->where('type', 'rankings_discovery')->latest('id')->firstOrFail();

    expect($aggregateRun->status)->not->toBe(ImportRunStatus::Running)
        ->and($aggregateRun->error_summary)->not->toBeNull()
        ->and($aggregateRun->error_summary)->not->toContain('Simulated child ImportRun finalization failure');
});

it('(b) propagates the RECOVERY exception instead when the best-effort aggregate finalize save itself fails, and the aggregate can remain Running', function (): void {
    Sleep::fake();
    Http::fake([
        DISCOVERY_RANKINGS_URL => Http::response(
            discoverTradersRankingsPayload([discoverTradersEntry('1001', 'trader_a')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    $childHasThrown = false;
    $aggregateHasThrown = false;

    ImportRun::saving(function (ImportRun $importRun) use (&$childHasThrown, &$aggregateHasThrown): void {
        if (! $childHasThrown && $importRun->type === 'rankings' && $importRun->status !== ImportRunStatus::Running) {
            $childHasThrown = true;

            throw new RuntimeException('Simulated child ImportRun finalization failure for test purposes.');
        }

        if (! $aggregateHasThrown && $importRun->type === 'rankings_discovery' && $importRun->status !== ImportRunStatus::Running) {
            $aggregateHasThrown = true;

            throw new RuntimeException('Simulated aggregate recovery finalize failure for test purposes.');
        }
    });

    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);
    $thrown = null;

    try {
        try {
            discoverTradersUseCase()->handle($request);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    // The recovery finalize() save itself failed, so its exception
    // propagates instead of the original child-persistence failure — this
    // is a genuinely different, real failure the caller sees, not the
    // original one, and not swallowed.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Simulated aggregate recovery finalize failure for test purposes.');

    $aggregateRun = ImportRun::query()->where('type', 'rankings_discovery')->latest('id')->firstOrFail();

    // The recovery save never committed, so the aggregate is left exactly
    // as createAggregateRun() left it: Running.
    expect($aggregateRun->status)->toBe(ImportRunStatus::Running)
        ->and($aggregateRun->finished_at)->toBeNull();
});
