<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the command with an explicit buffer (instead of relying on
 * Artisan::output()) so nothing is written to the real STDOUT during tests.
 */
function callEtoroDoctor(array $parameters = []): string
{
    $buffer = new BufferedOutput;

    Artisan::call('etoro:doctor', $parameters, $buffer);

    return $buffer->fetch();
}

function fakeRankingsResponse(): array
{
    return [
        'results' => [
            ['type' => 'smart-portfolio', 'username' => 'SmartPortfolioX'],
            ['type' => 'trader', 'username' => 'demo_trader_one', 'fullName' => 'SENTINEL_FULL_NAME'],
            ['type' => 'trader', 'username' => 'demo_trader_two'],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 5, 'hasNext' => false, 'totalItems' => 3],
    ];
}

function fakeEtoroResponses(): array
{
    return [
        'https://public-api.etoro.com/api/v1/me' => Http::response([
            'gcid' => 111,
            'username' => 'SENTINEL_ME_USERNAME',
            'scopes' => ['etoro-public:user-info:read'],
            'firstName' => 'SENTINEL_FIRST_NAME',
        ], 200),
        'https://public-api.etoro.com/api/v2/portfolios/rankings*' => Http::response(fakeRankingsResponse(), 200),
        'https://public-api.etoro.com/api/v1/user-info/people?*' => Http::response([
            'users' => [[
                'gcid' => 111,
                'username' => 'demo_trader_one',
                'firstName' => 'SENTINEL_FIRST_NAME',
                'lastName' => 'SENTINEL_LAST_NAME',
            ]],
        ], 200),
        'https://public-api.etoro.com/api/v1/user-info/people/*/gain' => Http::response([
            'monthly' => [['timestamp' => '2026-01-01T00:00:00Z', 'gain' => 12.34]],
            'yearly' => [],
        ], 200),
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response([
            'positions' => [['positionId' => 1, 'investmentPct' => 0.1, 'netProfit' => 5.5]],
            'socialTrades' => [],
        ], 200),
        'https://public-api.etoro.com/api/v1/trading/info/real/pnl' => Http::response([
            'clientPortfolio' => ['credit' => 918273.45, 'positions' => [], 'unrealizedPnL' => 0],
        ], 200),
        'https://public-api.etoro.com/api/v1/trading/info/demo/pnl' => Http::response([
            'clientPortfolio' => ['credit' => 555111.22, 'positions' => [], 'unrealizedPnL' => 0],
        ], 200),
    ];
}

beforeEach(function () {
    Sleep::fake();
});

it('does not send any request without --live', function () {
    Http::fake();

    $this->artisan('etoro:doctor')->run();

    Http::assertNothingSent();
});

it('reports missing credentials without calling the API', function () {
    config(['etoro.enabled' => true, 'etoro.api_key' => null, 'etoro.user_key' => null]);
    Http::fake();

    $this->artisan('etoro:doctor')->assertExitCode(1)->run();

    Http::assertNothingSent();
});

it('executes all seven live probes and selects a username from rankings', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.environment' => 'real',
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->assertExitCode(0)->run();

    Http::assertSentCount(7);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/user-info/people/demo_trader_one/gain');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/user-info/people/demo_trader_one/portfolio/live');
});

it('probes both real and demo P&L regardless of ETORO_ENVIRONMENT', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.environment' => 'demo',
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->run();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/trading/info/real/pnl');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/trading/info/demo/pnl');
});

it('never sends an Authorization header on any live probe', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->run();

    foreach (Http::recorded() as [$request]) {
        expect($request->hasHeader('Authorization'))->toBeFalse();
    }
});

it('skips dependent trader probes when rankings fails, but still runs both P&L probes', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);

    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v2/portfolios/rankings*'] = Http::response(['error' => 'boom'], 500);
    Http::fake($responses);

    $this->artisan('etoro:doctor', ['--live' => true])->run();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/user-info/people'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/trading/info/real/pnl');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/trading/info/demo/pnl');
});

it('classifies a 403 as requires_additional_scope for account-level probes and private_or_visibility_dependent for public trader probes', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);

    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v1/me'] = Http::response(['error' => 'forbidden'], 403);
    $responses['https://public-api.etoro.com/api/v1/user-info/people?*'] = Http::response(['error' => 'forbidden'], 403);
    Http::fake($responses);

    $output = callEtoroDoctor(['--live' => true]);

    expect($output)
        ->toContain('requires_additional_scope')
        ->toContain('private_or_visibility_dependent');
});

it('shows the request id and available rate-limit metadata for a probe, sanitized', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);

    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v1/me'] = Http::response(
        ['gcid' => 111, 'scopes' => []],
        200,
        ['X-RateLimit-Limit' => '45', 'X-RateLimit-Remaining' => '44'],
    );
    Http::fake($responses);

    $output = callEtoroDoctor(['--live' => true]);

    $meRequest = collect(Http::recorded())
        ->first(fn (array $pair) => $pair[0]->url() === 'https://public-api.etoro.com/api/v1/me');
    $meRequestId = $meRequest[0]->header('x-request-id')[0];

    expect($output)
        ->toContain($meRequestId)
        ->toContain('45')
        ->toContain('44');
});

it('never prints full payloads, personal data, or credential values to the console', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value-should-not-print',
        'etoro.user_key' => 'test-user-key-value-should-not-print',
    ]);
    Http::fake(fakeEtoroResponses());

    $output = callEtoroDoctor(['--live' => true]);

    expect($output)
        ->not->toContain('SENTINEL_FULL_NAME')
        ->not->toContain('SENTINEL_FIRST_NAME')
        ->not->toContain('SENTINEL_LAST_NAME')
        ->not->toContain('SENTINEL_ME_USERNAME')
        ->not->toContain('demo_trader_one')
        ->not->toContain('demo_trader_two')
        ->not->toContain('918273.45')
        ->not->toContain('555111.22')
        ->not->toContain('test-api-key-value-should-not-print')
        ->not->toContain('test-user-key-value-should-not-print')
        ->toContain('works');
});

it('does not create any raw response file without --capture-raw', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->run();

    expect(Storage::disk('local')->allFiles('etoro/raw'))->toBeEmpty();
});

it('creates raw response files only under gitignored private storage with --capture-raw', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $output = callEtoroDoctor(['--live' => true, '--capture-raw' => true]);

    $files = Storage::disk('local')->allFiles('etoro/raw');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect($file)->toStartWith('etoro/raw/');
    }

    expect($output)->toContain('personal and financial data');
});
