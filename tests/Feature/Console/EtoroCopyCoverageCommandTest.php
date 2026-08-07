<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the command with an explicit buffer (instead of relying on
 * Artisan::output()) so nothing is written to the real STDOUT during tests,
 * mirroring EtoroDoctorCommandTest's callEtoroDoctor() helper.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exitCode: int, output: string}
 */
function applicationCheckpointBCallEtoroCopyCoverage(array $parameters): array
{
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call('etoro:copy-coverage', $parameters, $buffer);

    return ['exitCode' => $exitCode, 'output' => $buffer->fetch()];
}

/**
 * Loads a fresh decode of the existing sanitized fixture on every call, same
 * approach as the Application Checkpoint A pipeline test.
 *
 * @return array<string, mixed>
 */
function applicationCheckpointBLivePortfolioFixturePayload(): array
{
    $json = file_get_contents(__DIR__.'/../../Fixtures/Etoro/live-portfolio.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
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
});

// --- A. success -----------------------------------------------------------

it('evaluates copy coverage end-to-end and prints the summary', function (): void {
    Http::fake([
        '*' => Http::response(applicationCheckpointBLivePortfolioFixturePayload(), 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Trader username[ .]+demo_trader\b/')
        ->and($output)->toMatch('/Copy amount[ .]+200\.00 \(20000 cents\)/')
        ->and($output)->toMatch('/Minimum position[ .]+1\.00 \(100 cents\)/')
        ->and($output)->toMatch('/Eligible positions[ .]+13\b/')
        ->and($output)->toMatch('/Skipped positions[ .]+3\b/')
        ->and($output)->toMatch('/Covered weight[ .]+99\.3%/');

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request) use ($output) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->toBe('https://public-api.etoro.com/api/v1/user-info/people/demo_trader/portfolio/live')
            ->and($request->hasHeader('x-request-id'))->toBeTrue();

        $requestId = $request->header('x-request-id')[0];

        expect($output)->toContain($requestId);

        return true;
    });
});

// --- B. invalid cents syntax ------------------------------------------------

it('rejects a decimal copy-amount-cents value with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20.5',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('copy-amount-cents');

    Http::assertSentCount(0);
});

it('rejects a non-numeric copy-amount-cents value with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => 'abc',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('copy-amount-cents');

    Http::assertSentCount(0);
});

// --- C. integer overflow -----------------------------------------------------

it('rejects a copy-amount-cents value above PHP_INT_MAX with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '99999999999999999999',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('copy-amount-cents');

    Http::assertSentCount(0);
});

it('rejects a minimum-position-cents value below PHP_INT_MIN with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '-99999999999999999999',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('minimum-position-cents');

    Http::assertSentCount(0);
});

// --- D. invalid semantic CLI amount ------------------------------------------

it('rejects a negative copy-amount-cents value with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '-1',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('copy-amount-cents');

    Http::assertSentCount(0);
});

it('rejects a zero minimum-position-cents value with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '0',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('minimum-position-cents');

    Http::assertSentCount(0);
});

// --- E. blank username --------------------------------------------------------

it('rejects a whitespace-only trader-username with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => '   ',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('trader-username');

    Http::assertSentCount(0);
});

// --- F. operational eToro failure ---------------------------------------------

it('fails cleanly, without a stack trace, when the live-portfolio endpoint returns a persistent 503', function (): void {
    Sleep::fake();
    Http::fake([
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response(['error' => 'boom'], 503),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('server_error')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('.php:');
});

// --- G. no secrets --------------------------------------------------------------

it('never prints credential sentinel values on success', function (): void {
    Http::fake([
        '*' => Http::response(applicationCheckpointBLivePortfolioFixturePayload(), 200),
    ]);

    ['output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($output)
        ->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});

it('never prints credential sentinel values on an operational failure', function (): void {
    Http::fake([
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)
        ->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});

// --- H. warnings ------------------------------------------------------------------

it('succeeds and shows a data-quality warning when the portfolio has unmodeled entries', function (): void {
    $payload = applicationCheckpointBLivePortfolioFixturePayload();
    $payload['socialTrades'] = [['socialTradeId' => 1]];

    Http::fake([
        '*' => Http::response($payload, 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = applicationCheckpointBCallEtoroCopyCoverage([
        'trader-username' => 'demo_trader',
        'copy-amount-cents' => '20000',
        'minimum-position-cents' => '100',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('unmodeled_portfolio_entries_present');
});
