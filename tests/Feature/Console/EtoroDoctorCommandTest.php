<?php

use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
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

it('shows attempt count, total duration, and final attempt duration columns in the sanitized table', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $output = callEtoroDoctor(['--live' => true]);

    expect($output)
        ->toContain('Attempts')
        ->toContain('Total Duration')
        ->toContain('Final Attempt Duration');
});

it('adds a recovered_after_retry note, without leaking the original transport message, when a probe succeeds after retrying', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);

    $callCount = 0;
    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v1/me'] = function () use (&$callCount) {
        $callCount++;

        return match ($callCount) {
            1 => throw new ConnectionException('SENTINEL_SHOULD_NOT_LEAK'),
            default => Http::response(['gcid' => 111, 'scopes' => []], 200),
        };
    };
    Http::fake($responses);

    $output = callEtoroDoctor(['--live' => true]);

    expect($output)
        ->toContain('recovered_after_retry')
        ->not->toContain('SENTINEL_SHOULD_NOT_LEAK');
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

// Raw capture is a double opt-in: ETORO_STORE_RAW_RESPONSES=true in config
// AND --capture-raw must both be present. All four combinations below are
// tested explicitly. The local .env is never read or modified — each test
// sets the config value in isolation via config(), independent of whatever
// the developer's own .env happens to contain.

it('config=false, no flag: does not create any raw response file', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.store_raw_responses' => false,
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->assertExitCode(0)->run();

    expect(Storage::disk('local')->allFiles('etoro/raw'))->toBeEmpty();
});

it('config=false, --capture-raw given: fails with a controlled configuration error and creates no file', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.store_raw_responses' => false,
    ]);
    Http::fake(fakeEtoroResponses());

    $output = callEtoroDoctor(['--live' => true, '--capture-raw' => true]);

    expect(Storage::disk('local')->allFiles('etoro/raw'))->toBeEmpty()
        ->and($output)->toContain('not enabled in configuration');

    Http::assertNothingSent();
});

it('config=true, no flag: does not create any raw response file', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.store_raw_responses' => true,
    ]);
    Http::fake(fakeEtoroResponses());

    $this->artisan('etoro:doctor', ['--live' => true])->assertExitCode(0)->run();

    expect(Storage::disk('local')->allFiles('etoro/raw'))->toBeEmpty();
});

it('config=true, --capture-raw given: creates raw response files only under gitignored private storage', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.store_raw_responses' => true,
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

it('with --only=live-portfolio and no --username, calls only rankings then live-portfolio', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $output = callEtoroDoctor(['--live' => true, '--only' => 'live-portfolio']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/portfolios/rankings'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/user-info/people/demo_trader_one/portfolio/live');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/me');
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://public-api.etoro.com/api/v1/user-info/people?'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/gain'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/trading/info/'));

    foreach (Http::recorded() as [$request]) {
        expect($request->method())->toBe('GET');
    }

    expect($output)->toContain('Trader live portfolio');
});

it('with --only=live-portfolio and --username given, skips the rankings dependency entirely', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    callEtoroDoctor(['--live' => true, '--only' => 'live-portfolio', '--username' => 'demo_trader_one']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/user-info/people/demo_trader_one/portfolio/live');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/portfolios/rankings'));
});

it('rejects an unknown --only value with a controlled validation failure and no HTTP calls', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake();

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'bogus-capability']);

    expect($exitCode)->not->toBe(0);
    Http::assertNothingSent();
});

it('does not create a raw file for --only without --capture-raw', function () {
    Storage::fake('local');
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    callEtoroDoctor(['--live' => true, '--only' => 'real-pnl']);

    expect(Storage::disk('local')->allFiles('etoro/raw'))->toBeEmpty();
});

it('returns a non-zero exit code for --only=me on a 401', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(['https://public-api.etoro.com/api/v1/me' => Http::response(['error' => 'unauthorized'], 401)]);

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'me']);

    expect($exitCode)->not->toBe(0);
});

it('returns a non-zero exit code for --only=me on a 503 that persists after bounded retries', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Sleep::fake();
    Http::fake(['https://public-api.etoro.com/api/v1/me' => Http::response(['error' => 'boom'], 503)]);

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'me']);

    expect($exitCode)->not->toBe(0);
    Http::assertSentCount(3);
});

it('returns a non-zero exit code for --only=me on a transport failure', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Sleep::fake();
    Http::fake(fn () => throw new ConnectionException('simulated connection failure'));

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'me']);

    expect($exitCode)->not->toBe(0);
});

it('returns a zero exit code for a successful --only=rankings', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake(fakeEtoroResponses());

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'rankings']);

    expect($exitCode)->toBe(0);
});

it('returns a non-zero exit code for a full live run when one probe fails', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v1/me'] = Http::response(['error' => 'forbidden'], 403);
    Http::fake($responses);

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true]);

    expect($exitCode)->not->toBe(0);
});

it('rejects a blank --username with a controlled validation failure, a non-zero exit code, and no HTTP calls', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake();

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'profile', '--username' => '']);

    expect($exitCode)->not->toBe(0);
    Http::assertNothingSent();
});

it('rejects a whitespace-only --username with a controlled validation failure, a non-zero exit code, and no HTTP calls', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
    Http::fake();

    $exitCode = Artisan::call('etoro:doctor', ['--live' => true, '--only' => 'profile', '--username' => '   ']);

    expect($exitCode)->not->toBe(0);
    Http::assertNothingSent();
});

it('never leaks the original transport message, credentials, username, or URL parameters in transport diagnostics', function () {
    config([
        'etoro.enabled' => true,
        'etoro.api_key' => 'test-api-key-value-should-not-print',
        'etoro.user_key' => 'test-user-key-value-should-not-print',
    ]);

    $original = new GuzzleConnectException(
        'SENTINEL_ORIGINAL_TRANSPORT_MESSAGE test-api-key-value-should-not-print demo_trader_one',
        new GuzzleRequest('GET', 'https://public-api.etoro.com/api/v1/user-info/people/demo_trader_one/portfolio/live?secret=leak'),
        null,
        ['errno' => 6],
    );

    $responses = fakeEtoroResponses();
    $responses['https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live'] = fn () => throw new ConnectionException($original->getMessage(), 0, $original);
    Http::fake($responses);

    $output = callEtoroDoctor(['--live' => true]);

    expect($output)
        ->not->toContain('SENTINEL_ORIGINAL_TRANSPORT_MESSAGE')
        ->not->toContain('test-api-key-value-should-not-print')
        ->not->toContain('demo_trader_one')
        ->not->toContain('secret=leak')
        ->toContain('dns_failure');
});
