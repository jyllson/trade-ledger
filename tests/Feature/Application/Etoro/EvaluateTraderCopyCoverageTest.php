<?php

use App\Analytics\ValueObjects\Money;
use App\Application\Etoro\EvaluateTraderCopyCoverage;
use App\Application\Etoro\EvaluateTraderCopyCoverageResult;
use App\Etoro\EtoroApiResponse;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Loads a fresh decode of the existing sanitized fixture on every call, same
 * approach as the other Etoro pipeline tests.
 *
 * @return array<string, mixed>
 */
function applicationCheckpointALivePortfolioFixturePayload(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/live-portfolio.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);
});

// --- Section 7: application pipeline test -------------------------------

it('evaluates trader copy coverage end-to-end through the real Etoro pipeline via container resolution', function (): void {
    Http::fake([
        '*' => Http::response(applicationCheckpointALivePortfolioFixturePayload(), 200),
    ]);

    $useCase = app(EvaluateTraderCopyCoverage::class);

    $result = $useCase->handle(
        traderUsername: 'demo_trader',
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
    );

    expect($result)->toBeInstanceOf(EvaluateTraderCopyCoverageResult::class)
        ->and($result->traderUsername)->toBe('demo_trader')
        ->and($result->coverage->eligibleCount)->toBe(13)
        ->and($result->coverage->skippedCount)->toBe(3)
        ->and($result->coverage->coveredWeight->partsPerBillion())->toBe(993_000_000);

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request) use ($result) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->toBe('https://public-api.etoro.com/api/v1/user-info/people/demo_trader/portfolio/live')
            ->and($request->hasHeader('x-api-key'))->toBeTrue()
            ->and($request->header('x-api-key'))->toBe(['test-api-key-value'])
            ->and($request->hasHeader('x-user-key'))->toBeTrue()
            ->and($request->header('x-user-key'))->toBe(['test-user-key-value'])
            ->and($request->hasHeader('x-request-id'))->toBeTrue();

        $requestId = $request->header('x-request-id')[0];

        expect($requestId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i')
            ->and($result->requestId)->toBe($requestId);

        return true;
    });

    // No raw eToro response capture/persistence happens anywhere in this
    // pipeline; store_raw_responses stays at its configured-off default.
    expect(config('etoro.store_raw_responses'))->toBeFalse();
});

// --- Section 8.1: exactly one client call, container override -----------

it('calls EtoroClient::userLivePortfolio exactly once with the given username, via a container override', function (): void {
    $fixturePayload = applicationCheckpointALivePortfolioFixturePayload();

    $mock = Mockery::mock(EtoroClient::class);
    $mock->shouldReceive('userLivePortfolio')
        ->once()
        ->with('demo_trader')
        ->andReturn(new EtoroApiResponse(
            payload: $fixturePayload,
            status: 200,
            requestId: 'mocked-request-id-1',
            attemptCount: 1,
            totalDurationMs: 1.23,
            finalAttemptDurationMs: 1.23,
            rateLimitHeaders: [],
        ));
    $mock->shouldNotReceive('authenticatedUser');
    $mock->shouldNotReceive('rankings');
    $mock->shouldNotReceive('userProfile');
    $mock->shouldNotReceive('userPerformance');
    $mock->shouldNotReceive('accountPnl');

    $this->app->instance(EtoroClient::class, $mock);

    $useCase = app(EvaluateTraderCopyCoverage::class);

    $result = $useCase->handle(
        traderUsername: 'demo_trader',
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
    );

    expect($result->traderUsername)->toBe('demo_trader')
        ->and($result->requestId)->toBe('mocked-request-id-1')
        ->and($result->coverage->eligibleCount)->toBe(13)
        ->and($result->coverage->skippedCount)->toBe(3)
        ->and($result->coverage->coveredWeight->partsPerBillion())->toBe(993_000_000);
});

// --- Section 8.2: transport exception identity ---------------------------

it('propagates an EtoroRequestException thrown by the client as the exact same instance', function (): void {
    $exception = EtoroRequestException::fromStatus(
        category: EtoroErrorCategory::ServerError,
        httpStatus: 503,
        requestId: 'failed-request-id',
    );

    $mock = Mockery::mock(EtoroClient::class);
    $mock->shouldReceive('userLivePortfolio')
        ->once()
        ->with('demo_trader')
        ->andThrow($exception);

    $this->app->instance(EtoroClient::class, $mock);

    $useCase = app(EvaluateTraderCopyCoverage::class);

    try {
        $useCase->handle('demo_trader', Money::fromCents(20_000), Money::fromCents(100));
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $caught) {
        expect($caught)->toBe($exception);
    }
});

// --- Section 8.3: mapping exception raised by the real mapper -----------

it('lets a real LivePortfolioMapper mapping failure propagate untranslated', function (): void {
    $mock = Mockery::mock(EtoroClient::class);
    $mock->shouldReceive('userLivePortfolio')
        ->once()
        ->with('demo_trader')
        ->andReturn(new EtoroApiResponse(
            payload: ['socialTrades' => []],
            status: 200,
            requestId: 'mapping-failure-request-id',
            attemptCount: 1,
            totalDurationMs: 1.0,
            finalAttemptDurationMs: 1.0,
            rateLimitHeaders: [],
        ));

    $this->app->instance(EtoroClient::class, $mock);

    $useCase = app(EvaluateTraderCopyCoverage::class);

    try {
        $useCase->handle('demo_trader', Money::fromCents(20_000), Money::fromCents(100));
        $this->fail('Expected EtoroMappingException to be thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField)
            ->and($exception->fieldPath)->toBe('positions');
    }
});

// --- Section 8.4: input invariant propagation -----------------------------

it('propagates the existing blank-username invariant from EtoroClient without duplicating it', function (): void {
    Http::fake();

    $useCase = app(EvaluateTraderCopyCoverage::class);

    expect(fn () => $useCase->handle('   ', Money::fromCents(20_000), Money::fromCents(100)))
        ->toThrow(InvalidArgumentException::class);

    Http::assertSentCount(0);
});
