<?php

use App\Etoro\DemoCopyUnregisterType;
use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\EtoroDemoCopyTransportOutcome;
use App\Etoro\EtoroRequestThrottle;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const DEMO_COPY_REQUEST_ID = '78451d56-9fbc-44ea-b371-6beeff852c58';
const DEMO_COPY_CLIENT_REQUEST_ID = '7f1da128-f516-4710-8474-915566c4b22e';

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.allow_write' => false,
        'etoro.allow_demo_copy' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);

    Http::preventStrayRequests();
});

it('pre-checks with a POST to the eligibility route and the documented body', function () {
    Http::fake(['*' => Http::response(['CID' => 1, 'parentCID' => 5551234, 'isSuccess' => true], 200)]);

    $response = app(EtoroDemoCopyClient::class)->preCheck(5551234, 50_000, DEMO_COPY_REQUEST_ID);

    expect($response->successful())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://public-api.etoro.com/api/v2/trading/copy/demo/eligibility')
            ->and($request->header('x-request-id'))->toBe([DEMO_COPY_REQUEST_ID])
            ->and($request->header('x-api-key'))->toBe(['test-api-key-value'])
            ->and($request->header('x-user-key'))->toBe(['test-user-key-value'])
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and($request->data())->toBe(['parentCID' => 5551234, 'amount' => 500]);

        return true;
    });
});

it('starts or adjusts with a POST carrying parentCID, amount and referenceID', function (int $amountCents, int|float $expectedAmount) {
    Http::fake(['*' => Http::response(['token' => '3c0e5b1f-8d2a-4f6e-9b7c-1a2b3c4d5e6f'], 200)]);

    app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, $amountCents, 'tl-ref-1', DEMO_COPY_REQUEST_ID);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo'
        && $request->header('x-request-id') === [DEMO_COPY_REQUEST_ID]
        && $request->data() === ['parentCID' => 5551234, 'amount' => $expectedAmount, 'referenceID' => 'tl-ref-1']);
})->with([
    'whole dollars' => [50_000, 500],
    'cents' => [50_025, 500.25],
    'remove funds' => [-10_000, -100],
]);

it('polls the outcome with a GET by referenceID', function () {
    Http::fake(['*' => Http::response(['referenceID' => 'tl-ref-1', 'isSuccess' => true, 'mirrorID' => 424242], 200)]);

    $response = app(EtoroDemoCopyClient::class)->pollOutcome('tl-ref-1', DEMO_COPY_REQUEST_ID);

    expect($response->payload['mirrorID'])->toBe(424242);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo/tl-ref-1');
});

it('closes through the JSON-body POST binding with the idempotency key', function () {
    Http::fake(['*' => Http::response(['token' => DEMO_COPY_CLIENT_REQUEST_ID], 200)]);

    app(EtoroDemoCopyClient::class)->close(424242, DemoCopyUnregisterType::Detach, DEMO_COPY_CLIENT_REQUEST_ID, DEMO_COPY_REQUEST_ID);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo/close'
        && $request->data() === ['mirrorID' => 424242, 'unregisterType' => 'Detach', 'clientRequestID' => DEMO_COPY_CLIENT_REQUEST_ID]);
});

it('sends exactly one attempt and never retries a write on 5xx', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 503)]);

    $response = app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID);

    expect($response->outcome)->toBe(EtoroDemoCopyTransportOutcome::Responded)
        ->and($response->httpStatus)->toBe(503)
        ->and($response->successful())->toBeFalse()
        ->and($response->refusedByEtoro())->toBeFalse();
    Http::assertSentCount(1);
});

it('reports a transport failure as connection_failed without retrying', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

    $response = app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID);

    expect($response->outcome)->toBe(EtoroDemoCopyTransportOutcome::ConnectionFailed)
        ->and($response->httpStatus)->toBeNull();
    Http::assertSentCount(0);
});

it('reports a 4xx as refused by eToro and keeps its documented error body', function (int $status) {
    Http::fake(['*' => Http::response(['error' => 'Amount must not be zero'], $status)]);

    $response = app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID);

    expect($response->refusedByEtoro())->toBeTrue()
        ->and($response->payload['error'])->toBe('Amount must not be zero');
})->with([400, 401, 403, 429]);

it('spends one shared etoro-api permit per request and sends nothing when the budget is exhausted', function () {
    RateLimiter::for(EtoroRequestThrottle::DEFAULT_LIMITER, fn (): Limit => Limit::perMinute(1));
    Http::fake(['*' => Http::response(['token' => 'x'], 200)]);

    $first = app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID);
    $second = app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-2', '0b0c6f6e-2d4b-4b8e-9f43-7d1c1d0f2a11');

    expect($first->successful())->toBeTrue()
        ->and($second->outcome)->toBe(EtoroDemoCopyTransportOutcome::NotSent)
        ->and($second->retryAfterSeconds)->toBeGreaterThan(0);
    Http::assertSentCount(1);
});

it('sends nothing when demo copy is disabled', function () {
    config(['etoro.allow_demo_copy' => false]);
    Http::fake();

    expect(fn () => app(EtoroDemoCopyClient::class)->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID))
        ->toThrow(EtoroDemoCopyNotAllowedException::class);

    Http::assertNothingSent();
});

it('sends nothing when the eToro integration is disabled', function () {
    config(['etoro.enabled' => false]);
    Http::fake();

    expect(fn () => app(EtoroDemoCopyClient::class)->preCheck(5551234, 50_000, DEMO_COPY_REQUEST_ID))
        ->toThrow(EtoroConfigurationException::class);

    Http::assertNothingSent();
});

it('rejects invalid input before sending', function (Closure $call) {
    Http::fake();

    expect(fn () => $call(app(EtoroDemoCopyClient::class)))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'zero amount' => [fn (EtoroDemoCopyClient $client) => $client->startOrAdjust(5551234, 0, 'tl-ref-1', DEMO_COPY_REQUEST_ID)],
    'non-positive parentCID' => [fn (EtoroDemoCopyClient $client) => $client->preCheck(0, 100, DEMO_COPY_REQUEST_ID)],
    'parentCID above int32' => [fn (EtoroDemoCopyClient $client) => $client->preCheck(2_147_483_648, 100, DEMO_COPY_REQUEST_ID)],
    'referenceID too long' => [fn (EtoroDemoCopyClient $client) => $client->startOrAdjust(5551234, 100, str_repeat('a', 36), DEMO_COPY_REQUEST_ID)],
    'referenceID with slash' => [fn (EtoroDemoCopyClient $client) => $client->pollOutcome('a/b', DEMO_COPY_REQUEST_ID)],
    'x-request-id not a uuid' => [fn (EtoroDemoCopyClient $client) => $client->preCheck(5551234, 100, 'not-a-uuid')],
    'mirrorID zero' => [fn (EtoroDemoCopyClient $client) => $client->close(0, DemoCopyUnregisterType::Close, DEMO_COPY_CLIENT_REQUEST_ID, DEMO_COPY_REQUEST_ID)],
    'clientRequestID not a uuid' => [fn (EtoroDemoCopyClient $client) => $client->close(1, DemoCopyUnregisterType::Close, 'x', DEMO_COPY_REQUEST_ID)],
]);

it('refuses a base_url that is not exactly the pinned origin and sends nothing', function (string $baseUrl) {
    config(['etoro.base_url' => $baseUrl]);
    Http::fake();

    foreach ([
        fn (EtoroDemoCopyClient $client) => $client->preCheck(5551234, 50_000, DEMO_COPY_REQUEST_ID),
        fn (EtoroDemoCopyClient $client) => $client->startOrAdjust(5551234, 50_000, 'tl-ref-1', DEMO_COPY_REQUEST_ID),
        fn (EtoroDemoCopyClient $client) => $client->pollOutcome('tl-ref-1', DEMO_COPY_REQUEST_ID),
        fn (EtoroDemoCopyClient $client) => $client->close(424242, DemoCopyUnregisterType::Close, DEMO_COPY_CLIENT_REQUEST_ID, DEMO_COPY_REQUEST_ID),
    ] as $call) {
        expect(fn () => $call(app(EtoroDemoCopyClient::class)))->toThrow(EtoroConfigurationException::class, 'bare https:// origin');
    }

    Http::assertNothingSent();
})->with([
    'real path + query' => ['https://public-api.etoro.com/api/v2/trading/copy/real?x='],
    'path' => ['https://public-api.etoro.com/api'],
    'query' => ['https://public-api.etoro.com?x=1'],
    'fragment' => ['https://public-api.etoro.com#x'],
    'userinfo' => ['https://user:pass@public-api.etoro.com'],
    'host as userinfo' => ['https://public-api.etoro.com@evil.example'],
    'other host' => ['https://evil.example'],
    'lookalike host' => ['https://public-api.etoro.com.evil.example'],
    'uppercase host' => ['https://PUBLIC-API.ETORO.COM'],
    'http' => ['http://public-api.etoro.com'],
    'other port' => ['https://public-api.etoro.com:8443'],
    'encoded slash' => ['https://public-api.etoro.com%2Fapi%2Fv2%2Ftrading%2Fcopy%2Freal'],
    'dot-dot' => ['https://public-api.etoro.com/../real'],
    'double slash' => ['https://public-api.etoro.com//'],
    'backslash' => ['https://public-api.etoro.com\\real'],
    'empty' => [''],
]);

it('accepts the pinned origin with a trailing slash or an explicit port 443, and still sends to the constant origin', function (string $baseUrl) {
    config(['etoro.base_url' => $baseUrl]);
    Http::fake(['*' => Http::response(['CID' => 1, 'parentCID' => 5551234, 'isSuccess' => true], 200)]);

    app(EtoroDemoCopyClient::class)->preCheck(5551234, 50_000, DEMO_COPY_REQUEST_ID);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo/eligibility');
})->with(['https://public-api.etoro.com/', 'https://public-api.etoro.com:443']);

it('never follows a redirect: a 3xx to the real account is returned as-is, and only the demo URL is requested', function () {
    Http::fake([
        'https://public-api.etoro.com/api/v2/trading/copy/demo/close' => Http::response('', 307, ['Location' => 'https://public-api.etoro.com/api/v2/trading/copy/real/close']),
        '*' => Http::response(['token' => DEMO_COPY_CLIENT_REQUEST_ID], 200),
    ]);

    $response = app(EtoroDemoCopyClient::class)->close(424242, DemoCopyUnregisterType::Close, DEMO_COPY_CLIENT_REQUEST_ID, DEMO_COPY_REQUEST_ID);

    expect($response->httpStatus)->toBe(307)
        ->and($response->successful())->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/real'));
});
