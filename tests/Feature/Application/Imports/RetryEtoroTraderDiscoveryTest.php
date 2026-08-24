<?php

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\ImportRankingPage;
use App\Application\Imports\ImportRunNotRetryableException;
use App\Application\Imports\RetryEtoroTraderDiscovery;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\RankingsMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const RETRY_DISCOVERY_RANKINGS_URL = 'https://public-api.etoro.com/api/v2/portfolios/rankings*';

function retryDiscoveryUseCase(): RetryEtoroTraderDiscovery
{
    return new RetryEtoroTraderDiscovery(
        new DiscoverEtoroTraders(new EtoroClient(app(Factory::class)), new RankingsMapper, new ImportRankingPage),
    );
}

/**
 * @return array<string, mixed>
 */
function retryDiscoveryEntry(string $cid, string $username): array
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
function retryDiscoveryRankingsPayload(array $entries, int $page, int $pageSize, int $totalItems, bool $hasNext): array
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eligibleRetryMetadata(array $overrides = []): array
{
    return array_replace_recursive([
        'query' => [
            'period' => 'lastYear',
            'start_page' => 1,
            'page_size' => 20,
            'max_pages' => 5,
            'sort' => null,
            'country' => null,
        ],
        'stop_reason' => 'request_failed',
        'pages_fetched' => 0,
        'child_import_run_ids' => [],
        'retryable' => true,
        'request_error_category' => 'server_error',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $metadataOverrides
 */
function eligibleOriginalRun(array $attributes = [], array $metadataOverrides = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'source' => 'etoro',
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Failed,
        'metadata' => eligibleRetryMetadata($metadataOverrides),
    ], $attributes));
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

afterEach(function (): void {
    Carbon::setTestNow(null);
});

// --- A. Happy path: reconstruction, lineage, original immutability ---------

it('retries an eligible Failed run and links retry_of_import_run_id back to it', function (): void {
    $original = eligibleOriginalRun();
    $originalSnapshot = $original->toArray();

    Http::fake([
        RETRY_DISCOVERY_RANKINGS_URL => Http::response(
            retryDiscoveryRankingsPayload([retryDiscoveryEntry('2001', 'trader_retry')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $result = retryDiscoveryUseCase()->handle($original);

    expect($result->importRun->type)->toBe('rankings_discovery')
        ->and($result->importRun->retry_of_import_run_id)->toBe($original->id)
        ->and($result->importRun->id)->not->toBe($original->id);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'period=lastYear'));

    // The original run is never mutated or reopened.
    expect($original->refresh()->toArray())->toEqual($originalSnapshot);
});

it('reconstructs the request from the original\'s sanitized query metadata, including sort/country', function (): void {
    $original = eligibleOriginalRun(metadataOverrides: [
        'query' => ['period' => 'thisYear', 'start_page' => 2, 'max_pages' => 3, 'sort' => '-copiers', 'country' => 'US'],
    ]);

    Http::fake([
        RETRY_DISCOVERY_RANKINGS_URL => Http::response(
            retryDiscoveryRankingsPayload([retryDiscoveryEntry('2002', 'trader_retry_b')], page: 2, pageSize: 20, totalItems: 2, hasNext: false),
            200,
        ),
    ]);

    retryDiscoveryUseCase()->handle($original);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'period=thisYear')
            && str_contains($request->url(), 'page=2')
            && str_contains($request->url(), 'sort=-copiers')
            && str_contains($request->url(), 'country=US');
    });
});

it('is eligible for a Partial original run, not only Failed', function (): void {
    $original = eligibleOriginalRun(['status' => ImportRunStatus::Partial]);

    Http::fake([
        RETRY_DISCOVERY_RANKINGS_URL => Http::response(
            retryDiscoveryRankingsPayload([retryDiscoveryEntry('2003', 'trader_retry_c')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $result = retryDiscoveryUseCase()->handle($original);

    expect($result->importRun->retry_of_import_run_id)->toBe($original->id);
});

it('links a retry chain immediately: C retries B retries A means C.retry_of_import_run_id = B.id', function (): void {
    $runA = eligibleOriginalRun();

    Http::fake([RETRY_DISCOVERY_RANKINGS_URL => Http::response(['error' => 'boom'], 500)]);

    $runB = retryDiscoveryUseCase()->handle($runA)->importRun;

    expect($runB->status)->toBe(ImportRunStatus::Failed)
        ->and($runB->metadata['retryable'])->toBeTrue()
        ->and($runB->retry_of_import_run_id)->toBe($runA->id);

    $runC = retryDiscoveryUseCase()->handle($runB)->importRun;

    expect($runC->retry_of_import_run_id)->toBe($runB->id)
        ->and($runC->retry_of_import_run_id)->not->toBe($runA->id);
});

it('produces no duplicate traders across two separate retries of the same original run', function (): void {
    $original = eligibleOriginalRun();
    Trader::factory()->create(['external_cid' => '2004', 'username' => 'trader_retry_d']);

    Http::fake([
        RETRY_DISCOVERY_RANKINGS_URL => Http::response(
            retryDiscoveryRankingsPayload([retryDiscoveryEntry('2004', 'trader_retry_d')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $first = retryDiscoveryUseCase()->handle($original);
    $second = retryDiscoveryUseCase()->handle($original);

    expect($first->importRun->id)->not->toBe($second->importRun->id)
        ->and($first->importRun->retry_of_import_run_id)->toBe($original->id)
        ->and($second->importRun->retry_of_import_run_id)->toBe($original->id)
        ->and(Trader::query()->count())->toBe(1);
});

// --- B. canRetry() mirrors handle()'s guards, with no HTTP/DB side effects -

dataset('ineligible original runs', [
    'not persisted (unsaved model)' => [
        fn (): ImportRun => ImportRun::factory()->make([
            'source' => 'etoro',
            'type' => 'rankings_discovery',
            'status' => ImportRunStatus::Failed,
            'metadata' => eligibleRetryMetadata(),
        ]),
    ],
    'wrong source' => [
        fn (): ImportRun => eligibleOriginalRun(['source' => 'not-etoro']),
    ],
    'wrong type: per-page rankings child' => [
        fn (): ImportRun => eligibleOriginalRun(['type' => 'rankings']),
    ],
    'wrong type: profile run' => [
        fn (): ImportRun => eligibleOriginalRun(['type' => 'profile']),
    ],
    'wrong status: Completed' => [
        fn (): ImportRun => eligibleOriginalRun(['status' => ImportRunStatus::Completed]),
    ],
    'wrong status: Running' => [
        fn (): ImportRun => eligibleOriginalRun(['status' => ImportRunStatus::Running]),
    ],
    'wrong status: Pending' => [
        fn (): ImportRun => eligibleOriginalRun(['status' => ImportRunStatus::Pending]),
    ],
    'not marked retryable: false' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retryable' => false]),
    ],
    'not marked retryable: missing key' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => ['query' => ['period' => 'lastYear', 'start_page' => 1, 'max_pages' => 5, 'sort' => null, 'country' => null]]]),
    ],
    'not marked retryable: truthy but non-strict-true string' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retryable' => '1']),
    ],
    'malformed metadata: JSON scalar string, not an array (in-memory instance)' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => 'not-an-array']),
    ],
    'malformed metadata: JSON scalar bool, not an array (in-memory instance)' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => true]),
    ],
    'malformed metadata: JSON scalar int, not an array (in-memory instance)' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => 42]),
    ],
    // Fresh reload from the database — exercises the persisted cast
    // boundary itself (getRawOriginal()/json_decode() against the actual
    // stored column), not just the in-memory instance ->create() returns.
    'malformed metadata: JSON scalar string, not an array (fresh DB reload)' => [
        fn (): ImportRun => ImportRun::query()->findOrFail(eligibleOriginalRun(['metadata' => 'not-an-array'])->id),
    ],
    'malformed metadata: JSON scalar bool, not an array (fresh DB reload)' => [
        fn (): ImportRun => ImportRun::query()->findOrFail(eligibleOriginalRun(['metadata' => true])->id),
    ],
    'malformed metadata: JSON scalar int, not an array (fresh DB reload)' => [
        fn (): ImportRun => ImportRun::query()->findOrFail(eligibleOriginalRun(['metadata' => 42])->id),
    ],
    'malformed metadata: null metadata (in-memory instance)' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => null]),
    ],
    'malformed metadata: null metadata (fresh DB reload)' => [
        fn (): ImportRun => ImportRun::query()->findOrFail(eligibleOriginalRun(['metadata' => null])->id),
    ],
    'malformed query: missing query key' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => ['retryable' => true]]),
    ],
    'malformed query: query is not an array' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => 'not-an-array']),
    ],
    'malformed query: blank period' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['period' => '   ']]),
    ],
    'malformed query: missing period' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => array_merge(eligibleRetryMetadata(), ['query' => ['start_page' => 1, 'max_pages' => 5, 'sort' => null, 'country' => null]])]),
    ],
    'malformed query: start_page is a string' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['start_page' => '1']]),
    ],
    'malformed query: start_page below 1' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['start_page' => 0]]),
    ],
    'malformed query: max_pages above the ceiling' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['max_pages' => 21]]),
    ],
    'malformed query: max_pages below 1' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['max_pages' => 0]]),
    ],
    'malformed query: sort is not a string' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['sort' => 123]]),
    ],
    'malformed query: country is not a string' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['query' => ['country' => 123]]),
    ],
    'retry_not_before: relative/free-form string ("tomorrow")' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => 'tomorrow']),
    ],
    'retry_not_before: missing timezone offset' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => '2026-08-21T14:05:00']),
    ],
    'retry_not_before: space instead of T separator' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => '2026-08-21 14:05:00+00:00']),
    ],
    'retry_not_before: extra fractional-second precision' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => '2026-08-21T14:05:00.123456+00:00']),
    ],
    'retry_not_before: non-string (int)' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => 1755784800]),
    ],
    'retry_not_before: one second in the future' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['retry_not_before' => now()->addSecond()->toIso8601String()]),
    ],

    // --- Cross-field consistency: retryable=true alone is never trusted ---

    'inconsistent: retryable=true but stop_reason is natural_completion' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['stop_reason' => 'natural_completion']),
    ],
    'inconsistent: retryable=true but stop_reason is missing entirely' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => ['retryable' => true, 'query' => eligibleRetryMetadata()['query']]]),
    ],
    'inconsistent: retryable=true, request_failed, but category is validation (non-transient)' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 'validation']),
    ],
    'inconsistent: retryable=true, request_failed, but category is authentication (non-transient)' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 'authentication']),
    ],
    'inconsistent: retryable=true, request_failed, but category is authorization (non-transient)' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 'authorization']),
    ],
    'inconsistent: retryable=true, request_failed, but category is not_found (non-transient)' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 'not_found']),
    ],
    'inconsistent: retryable=true, request_failed, but request_error_category is missing' => [
        fn (): ImportRun => eligibleOriginalRun(['metadata' => array_diff_key(eligibleRetryMetadata(), ['request_error_category' => true])]),
    ],
    'inconsistent: retryable=true, request_failed, but request_error_category is not a string' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 42]),
    ],
    'inconsistent: retryable=true, request_failed, but request_error_category is an unknown value' => [
        fn (): ImportRun => eligibleOriginalRun(metadataOverrides: ['request_error_category' => 'not_a_real_category']),
    ],
]);

it('rejects an ineligible original run via handle(), with no HTTP call and no ImportRun write', function (Closure $makeOriginal): void {
    $original = $makeOriginal();
    $importRunCountBefore = ImportRun::query()->count();

    expect(fn () => retryDiscoveryUseCase()->handle($original))
        ->toThrow(ImportRunNotRetryableException::class);

    Http::assertNothingSent();
    expect(ImportRun::query()->count())->toBe($importRunCountBefore);
})->with('ineligible original runs');

it('canRetry() returns false for the exact same ineligible original run, with no HTTP call and no ImportRun write', function (Closure $makeOriginal): void {
    $original = $makeOriginal();
    $importRunCountBefore = ImportRun::query()->count();

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeFalse();

    Http::assertNothingSent();
    expect(ImportRun::query()->count())->toBe($importRunCountBefore);
})->with('ineligible original runs');

it('canRetry() returns true for the exact same eligible run handle() would accept, with no HTTP call and no ImportRun write', function (): void {
    $original = eligibleOriginalRun();
    $importRunCountBefore = ImportRun::query()->count();

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeTrue();

    Http::assertNothingSent();
    expect(ImportRun::query()->count())->toBe($importRunCountBefore);
});

it('canRetry() returns true for an eligible run reloaded fresh from the database', function (): void {
    $original = ImportRun::query()->findOrFail(eligibleOriginalRun()->id);

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeTrue();

    Http::assertNothingSent();
});

it('ignores an in-memory metadata mutation the caller made without persisting it, authorizing only from the database', function (): void {
    $original = eligibleOriginalRun();

    // Mutate the in-memory attribute only — never saved.
    $original->metadata = array_merge($original->metadata, ['retryable' => false]);

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeTrue();
});

it('canRetry() returns true when retry_not_before is exactly now, using frozen time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21T14:05:00+00:00'));

    $original = eligibleOriginalRun(metadataOverrides: ['retry_not_before' => now()->toIso8601String()]);

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeTrue();
});

it('canRetry() returns false when retry_not_before is one second in the future, using frozen time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21T14:05:00+00:00'));

    $original = eligibleOriginalRun(metadataOverrides: ['retry_not_before' => now()->addSecond()->toIso8601String()]);

    expect(retryDiscoveryUseCase()->canRetry($original))->toBeFalse();
});

it('never leaks which specific check failed through the exception message', function (Closure $makeOriginal): void {
    $original = $makeOriginal();

    try {
        retryDiscoveryUseCase()->handle($original);
        $this->fail('Expected ImportRunNotRetryableException to be thrown.');
    } catch (ImportRunNotRetryableException $exception) {
        expect($exception->getMessage())->not->toContain('lastYear')
            ->and($exception->getMessage())->toBeString();
    }
})->with('ineligible original runs');
