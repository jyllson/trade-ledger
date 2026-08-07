<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the command with an explicit buffer (instead of relying on
 * Artisan::output()) so nothing is written to the real STDOUT during tests,
 * mirroring EtoroCopyCoverageCommandTest's equivalent helper. Named
 * distinctly (etoroCopyTarget... rather than applicationCheckpointB...) to
 * avoid a global Pest function name collision with that sibling file.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exitCode: int, output: string}
 */
function etoroCopyTargetCallCommand(array $parameters): array
{
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call('etoro:copy-target', $parameters, $buffer);

    return ['exitCode' => $exitCode, 'output' => $buffer->fetch()];
}

/**
 * Loads a fresh decode of the existing sanitized fixture on every call, same
 * approach as the Application Checkpoint A and etoro:copy-coverage tests.
 *
 * @return array<string, mixed>
 */
function etoroCopyTargetFixturePayload(): array
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

// --- A. happy path -----------------------------------------------------

it('finds the minimum copy amount for a 95% target end-to-end and prints the summary', function (): void {
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Trader username[ .]+demo_trader\b/')
        ->and($output)->toMatch('/Target coverage[ .]+95%/')
        ->and($output)->toMatch('/Minimum position[ .]+1\.00 \(100 cents\)/')
        ->and($output)->toMatch('/Platform minimum copy[ .]+200\.00 \(20000 cents\)/')
        ->and($output)->toMatch('/Mathematical minimum copy[ .]+33\.34 \(3334 cents\)/')
        ->and($output)->toMatch('/Effective minimum copy[ .]+200\.00 \(20000 cents\)/')
        ->and($output)->toMatch('/Achieved coverage[ .]+99\.3%/')
        ->and($output)->toMatch('/Covered observed weight[ .]+99\.3%/')
        ->and($output)->toMatch('/Incomplete source data[ .]+no\b/')
        ->and($output)->toMatch('/Warnings[ .]+none\b/');

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

it('never prints credential sentinel values on success', function (): void {
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    ['output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($output)
        ->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});

// --- B. target-coverage-percent parser ----------------------------------

it('accepts a valid target-coverage-percent value and displays the exact expected Target coverage', function (string $value, string $expectedFormatted): void {
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => $value,
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Target coverage[ .]+'.preg_quote($expectedFormatted, '/').'(?!\S)/');

    Http::assertSentCount(1);
})->with([
    // input => [input, expected formatted "Target coverage" value]
    '95' => ['95', '95%'],
    '95.5' => ['95.5', '95.5%'],
    '95.0000001' => ['95.0000001', '95.0000001%'],
    '0.05' => ['0.05', '0.05%'],
    '0.0000001' => ['0.0000001', '0.0000001%'],
    '100' => ['100', '100%'],
    '100.0000000' => ['100.0000000', '100%'],
]);

it('rejects an invalid target-coverage-percent value with no HTTP calls', function (string $value): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => $value,
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('target-coverage-percent');

    Http::assertSentCount(0);
})->with([
    '0' => ['0'],
    '100.0000001' => ['100.0000001'],
    '-1' => ['-1'],
    '+95' => ['+95'],
    '95.' => ['95.'],
    '.95' => ['.95'],
    '95.12345678' => ['95.12345678'],
    '1e2' => ['1e2'],
    '95,5' => ['95,5'],
    'abc' => ['abc'],
]);

// --- C. minimum-position-cents parser -------------------------------------

it('accepts a valid minimum-position-cents value', function (string $value): void {
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    ['exitCode' => $exitCode] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => $value,
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->toBe(0);
})->with([
    '1' => ['1'],
    '100' => ['100'],
]);

it('accepts PHP_INT_MAX as a syntactically/range-valid minimum-position-cents value and reaches the use case', function (): void {
    // PHP_INT_MAX cents is syntactically and range valid for the parser, so
    // the command must proceed to call the use case (one outbound GET) —
    // whether the domain calculator can then represent every resulting
    // breakpoint for this particular portfolio is a separate, legitimate
    // CoverageCalculationException concern, not a parser rejection.
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => (string) PHP_INT_MAX,
        'platform-minimum-copy-cents' => '20000',
    ]);

    Http::assertSentCount(1);
});

it('rejects an invalid minimum-position-cents value with no HTTP calls', function (string $value): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => $value,
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('minimum-position-cents');

    Http::assertSentCount(0);
})->with([
    'blank' => [''],
    '0' => ['0'],
    '-1' => ['-1'],
    '1.0' => ['1.0'],
    '1e2' => ['1e2'],
    'PHP_INT_MAX + 1' => [bcadd((string) PHP_INT_MAX, '1')],
]);

// --- D. platform-minimum-copy-cents parser --------------------------------

it('accepts a valid platform-minimum-copy-cents value', function (string $value): void {
    Http::fake([
        '*' => Http::response(etoroCopyTargetFixturePayload(), 200),
    ]);

    ['exitCode' => $exitCode] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => $value,
    ]);

    expect($exitCode)->toBe(0);
})->with([
    '0' => ['0'],
    '20000' => ['20000'],
]);

it('rejects an invalid platform-minimum-copy-cents value with no HTTP calls', function (string $value): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => $value,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('platform-minimum-copy-cents');

    Http::assertSentCount(0);
})->with([
    '-1' => ['-1'],
    '1.0' => ['1.0'],
    '1e2' => ['1e2'],
    'PHP_INT_MAX + 1' => [bcadd((string) PHP_INT_MAX, '1')],
]);

// --- E. blank username ------------------------------------------------------

it('rejects a whitespace-only trader-username with no HTTP calls', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => '   ',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('trader-username');

    Http::assertSentCount(0);
});

// --- F. no-positive domain result is a successful evaluation ----------------

it('succeeds with N/A amounts and an observed_weight_not_whole warning for an empty observed portfolio', function (): void {
    Http::fake([
        '*' => Http::response(['positions' => [], 'socialTrades' => []], 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Target coverage[ .]+95%/')
        ->and($output)->toMatch('/Mathematical minimum copy[ .]+N\/A/')
        ->and($output)->toMatch('/Effective minimum copy[ .]+N\/A/')
        ->and($output)->toMatch('/Achieved coverage[ .]+N\/A/')
        ->and($output)->toMatch('/Covered observed weight[ .]+N\/A/')
        ->and($output)->toMatch('/Incomplete source data[ .]+no\b/')
        ->and($output)->toMatch('/Warnings[ .]+observed_weight_not_whole/')
        ->and($output)->not->toContain('0.00 (0 cents)');

    Http::assertSentCount(1);
});

// --- G. incomplete source data / warnings ------------------------------------

it('shows Incomplete source data = yes and the unmodeled-entries warning when the portfolio has unmodeled social trade entries', function (): void {
    $payload = etoroCopyTargetFixturePayload();
    $payload['socialTrades'] = [['socialTradeId' => 1]];

    Http::fake([
        '*' => Http::response($payload, 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Incomplete source data[ .]+yes\b/')
        ->and($output)->toContain('unmodeled_portfolio_entries_present');
});

// --- H. operational exceptions -----------------------------------------------

it('fails cleanly, without a stack trace, when the live-portfolio endpoint returns a persistent 503', function (): void {
    Sleep::fake();
    Http::fake([
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response(['error' => 'boom'], 503),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('server_error')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('.php:');
});

it('never prints credential sentinel values on an operational failure', function (): void {
    Http::fake([
        'https://public-api.etoro.com/api/v1/user-info/people/*/portfolio/live' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)
        ->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});

it('fails cleanly with no HTTP calls when the eToro integration is disabled', function (): void {
    config(['etoro.enabled' => false]);

    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('disabled');

    Http::assertSentCount(0);
});

it('fails cleanly, without the raw payload, when the live-portfolio response body is not a JSON object/array', function (): void {
    Http::fake([
        '*' => Http::response('"unexpected-scalar-body"', 200, ['Content-Type' => 'application/json']),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('did not decode to a JSON object/array')
        ->and($output)->not->toContain('unexpected-scalar-body');
});

it('fails cleanly, without the raw payload, when the live-portfolio response is missing a required field', function (): void {
    Http::fake([
        '*' => Http::response(['socialTrades' => []], 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '95',
        'minimum-position-cents' => '100',
        'platform-minimum-copy-cents' => '20000',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('positions')
        ->and($output)->toContain('required field is missing');
});

it('fails cleanly with a controlled CoverageCalculationException message when the target requires an unrepresentable breakpoint', function (): void {
    Http::fake([
        '*' => Http::response([
            'positions' => [
                ['positionId' => 1, 'instrumentId' => 1, 'investmentPct' => 99.9999999],
                ['positionId' => 2, 'instrumentId' => 2, 'investmentPct' => 0.0000001],
            ],
            'socialTrades' => [],
        ], 200),
    ]);

    ['exitCode' => $exitCode, 'output' => $output] = etoroCopyTargetCallCommand([
        'trader-username' => 'demo_trader',
        'target-coverage-percent' => '100',
        'minimum-position-cents' => '10000000000',
        'platform-minimum-copy-cents' => '0',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('outside the representable range')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('.php:');
});
