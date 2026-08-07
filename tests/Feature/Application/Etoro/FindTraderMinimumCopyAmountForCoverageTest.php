<?php

use App\Analytics\Data\CoverageWarning;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverageResult;
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
function findTraderMinimumCopyAmountFixturePayload(): array
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

// --- Happy path: end-to-end pipeline, exact 95% target fixture result ------

it('finds the minimum copy amount for a 95% target end-to-end through the real Etoro pipeline via container resolution', function (): void {
    Http::fake([
        '*' => Http::response(findTraderMinimumCopyAmountFixturePayload(), 200),
    ]);

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    $result = $useCase->handle(
        traderUsername: 'demo_trader',
        targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(20_000),
    );

    expect($result)->toBeInstanceOf(FindTraderMinimumCopyAmountForCoverageResult::class)
        ->and($result->traderUsername)->toBe('demo_trader')
        ->and($result->coverageTarget->mathematicalMinimumCopyAmount?->cents())->toBe(3_334)
        ->and($result->coverageTarget->effectiveMinimumCopyAmount?->cents())->toBe(20_000)
        ->and($result->coverageTarget->targetRatio->partsPerBillion())->toBe(950_000_000)
        ->and($result->coverageTarget->achievedRatio?->partsPerBillion())->toBe(993_000_000)
        ->and($result->coverageTarget->coveredRawWeight?->partsPerBillion())->toBe(993_000_000)
        ->and($result->coverageTarget->hasIncompleteSourceData)->toBeFalse()
        ->and($result->coverageTarget->warnings)->toBe([]);

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
});

// --- Exactly one logical eToro endpoint call --------------------------------

it('calls EtoroClient::userLivePortfolio exactly once with the given username, never another endpoint, via a container override', function (): void {
    $fixturePayload = findTraderMinimumCopyAmountFixturePayload();

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

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    $result = $useCase->handle(
        traderUsername: 'demo_trader',
        targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(20_000),
    );

    expect($result->traderUsername)->toBe('demo_trader')
        ->and($result->requestId)->toBe('mocked-request-id-1')
        ->and($result->coverageTarget->mathematicalMinimumCopyAmount?->cents())->toBe(3_334)
        ->and($result->coverageTarget->effectiveMinimumCopyAmount?->cents())->toBe(20_000);
});

// --- Exception propagation ---------------------------------------------------

it('propagates the existing blank-username invariant from EtoroClient without duplicating it', function (): void {
    Http::fake();

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    expect(fn () => $useCase->handle(
        traderUsername: '   ',
        targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(20_000),
    ))->toThrow(InvalidArgumentException::class);

    Http::assertSentCount(0);
});

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

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    try {
        $useCase->handle(
            traderUsername: 'demo_trader',
            targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
            minimumPositionAmount: Money::fromCents(100),
            platformMinimumCopyAmount: Money::fromCents(20_000),
        );
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $caught) {
        expect($caught)->toBe($exception);
    }
});

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

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    try {
        $useCase->handle(
            traderUsername: 'demo_trader',
            targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
            minimumPositionAmount: Money::fromCents(100),
            platformMinimumCopyAmount: Money::fromCents(20_000),
        );
        $this->fail('Expected EtoroMappingException to be thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField)
            ->and($exception->fieldPath)->toBe('positions');
    }
});

// --- No-positive domain result is not reinterpreted by the application layer

it('propagates the domain no-positive-position result unchanged: null amounts, hasIncompleteSourceData=false, ObservedWeightNotWhole warning only', function (): void {
    Http::fake([
        '*' => Http::response(['positions' => [], 'socialTrades' => []], 200),
    ]);

    $useCase = app(FindTraderMinimumCopyAmountForCoverage::class);

    $result = $useCase->handle(
        traderUsername: 'demo_trader',
        targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(20_000),
    );

    expect($result->coverageTarget->mathematicalMinimumCopyAmount)->toBeNull()
        ->and($result->coverageTarget->effectiveMinimumCopyAmount)->toBeNull()
        ->and($result->coverageTarget->achievedRatio)->toBeNull()
        ->and($result->coverageTarget->coveredRawWeight)->toBeNull()
        ->and($result->coverageTarget->hasIncompleteSourceData)->toBeFalse()
        ->and($result->coverageTarget->warnings)->toBe([CoverageWarning::ObservedWeightNotWhole]);

    Http::assertSentCount(1);
});
