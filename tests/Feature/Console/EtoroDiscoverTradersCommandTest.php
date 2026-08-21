<?php

use App\Models\ImportRun;
use App\Models\Trader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

const DISCOVER_COMMAND_RANKINGS_URL = 'https://public-api.etoro.com/api/v2/portfolios/rankings*';

/**
 * @param  array<string, mixed>  $parameters
 * @return array{exitCode: int, output: string}
 */
function checkpointEDiscoverTradersCall(array $parameters): array
{
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call('etoro:discover-traders', $parameters, $buffer);

    return ['exitCode' => $exitCode, 'output' => $buffer->fetch()];
}

/**
 * @return array<string, mixed>
 */
function checkpointEDiscoverTradersRankingsPayload(bool $hasNext = false): array
{
    return [
        'results' => [
            ['cid' => '1001', 'username' => 'trader_a', 'type' => 'trader', 'subType' => 'pi-certified', 'copiers' => 100],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 20, 'totalItems' => 1, 'hasNext' => $hasNext],
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
    Sleep::fake();
});

// --- A. Success -------------------------------------------------------------

it('exits 0 with a sanitized aggregate summary on natural completion', function (): void {
    Http::fake([DISCOVER_COMMAND_RANKINGS_URL => Http::response(checkpointEDiscoverTradersRankingsPayload(), 200)]);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Discovery run status[ .]+completed/')
        ->and($output)->toMatch('/Stop reason[ .]+natural_completion/')
        ->and($output)->toMatch('/Pages fetched[ .]+1\b/')
        ->and($output)->toMatch('/Entries succeeded[ .]+1\b/')
        ->and($output)->toMatch('/Entries rejected[ .]+0\b/')
        ->and($output)->not->toContain('trader_a')
        ->and($output)->not->toContain('1001')
        ->and(Trader::query()->count())->toBe(1);

    Http::assertSentCount(1);
});

// --- B. Invalid input --------------------------------------------------------

it('rejects a blank period with exit 2, a generic sanitized message, and no HTTP call', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => '   ']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Discovery input is invalid: check period, max-pages (1-20), and start-page (>=1).')
        ->and($output)->not->toContain('must not be blank')
        ->and(ImportRun::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('rejects a max-pages value above the ceiling with exit 2, a generic sanitized message, and no HTTP call', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear', '--max-pages' => '21']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Discovery input is invalid: check period, max-pages (1-20), and start-page (>=1).')
        ->and($output)->not->toContain('between 1 and 20');

    Http::assertNothingSent();
});

it('rejects a non-integer max-pages with exit 2 and no HTTP call', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear', '--max-pages' => '1.5']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('max-pages');

    Http::assertNothingSent();
});

it('rejects a start-page of zero with exit 2 and no HTTP call', function (): void {
    Http::fake();

    ['exitCode' => $exitCode] = checkpointEDiscoverTradersCall(['period' => 'lastYear', '--start-page' => '0']);

    expect($exitCode)->toBe(2);
    Http::assertNothingSent();
});

// --- C. Partial (rejections / page limit): exit 3 ---------------------------

it('exits 3 when the page limit is reached before natural completion', function (): void {
    Http::fake([DISCOVER_COMMAND_RANKINGS_URL => Http::response(checkpointEDiscoverTradersRankingsPayload(hasNext: true), 200)]);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear', '--max-pages' => '1']);

    expect($exitCode)->toBe(3)
        ->and($output)->toMatch('/Discovery run status[ .]+partial/')
        ->and($output)->toContain('page limit');
});

it('exits 3 when a row-level rejection occurs even on natural completion', function (): void {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'trader_a']);
    Http::fake([DISCOVER_COMMAND_RANKINGS_URL => Http::response(checkpointEDiscoverTradersRankingsPayload(), 200)]);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(3)
        ->and($output)->toMatch('/Entries rejected[ .]+1\b/')
        ->and($output)->not->toContain('trader_a')
        ->and($output)->not->toContain('existing-cid');
});

// --- D. Fatal: exit 1 --------------------------------------------------------

it('exits 1 with a sanitized generic message on a fatal request failure', function (): void {
    Http::fake([DISCOVER_COMMAND_RANKINGS_URL => Http::response(['error' => 'server exploded'], 500)]);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/Discovery run status[ .]+failed/')
        ->and($output)->not->toContain('server exploded');
});

it('exits 1 for a configuration error with no HTTP call', function (): void {
    config(['etoro.enabled' => false]);
    Http::fake();

    ['exitCode' => $exitCode] = checkpointEDiscoverTradersCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(1);
    Http::assertNothingSent();
});

// --- E. Architecture-adjacent behavior: no direct EtoroClient reference in output ----

it('never prints an exception getMessage(), a credential value, or a raw payload', function (): void {
    Http::fake([DISCOVER_COMMAND_RANKINGS_URL => Http::response(['error' => 'sentinel-should-not-appear'], 500)]);

    ['output' => $output] = checkpointEDiscoverTradersCall(['period' => 'lastYear']);

    expect($output)->not->toContain('sentinel-should-not-appear')
        ->and($output)->not->toContain('test-api-key-value-sentinel')
        ->and($output)->not->toContain('test-user-key-value-sentinel');
});
