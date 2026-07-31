<?php

use App\Etoro\EtoroClient;
use App\Etoro\EtoroEnvironment;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\RankingQuery;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);

    Sleep::fake();
});

it('sends a single GET request with the required headers and a fresh request id', function () {
    Http::fake(['*' => Http::response(['gcid' => 1, 'username' => 'x', 'scopes' => []], 200)]);

    app(EtoroClient::class)->authenticatedUser();

    Http::assertSent(function (Request $request) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->toBe('https://public-api.etoro.com/api/v1/me')
            ->and($request->hasHeader('x-request-id'))->toBeTrue()
            ->and($request->header('x-api-key'))->toBe(['test-api-key-value'])
            ->and($request->header('x-user-key'))->toBe(['test-user-key-value'])
            ->and($request->hasHeader('Authorization'))->toBeFalse();

        return true;
    });
});

it('uses a different x-request-id for every request', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    app(EtoroClient::class)->authenticatedUser();
    app(EtoroClient::class)->authenticatedUser();

    $requestIds = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0]->header('x-request-id')[0]);

    expect($requestIds->unique())->toHaveCount(2);
});

it('never sends any HTTP verb other than GET across all typed methods', function () {
    Http::fake(['*' => Http::response(['results' => [], 'pagination' => ['page' => 1, 'pageSize' => 5, 'hasNext' => false]], 200)]);

    $client = app(EtoroClient::class);
    $client->authenticatedUser();
    $client->rankings(new RankingQuery(period: 'CurrMonth'));
    $client->userProfile('someuser');
    $client->userPerformance('someuser');
    $client->userLivePortfolio('someuser');
    $client->accountPnl(EtoroEnvironment::Real);
    $client->accountPnl(EtoroEnvironment::Demo);

    foreach (Http::recorded() as [$request]) {
        expect($request->method())->toBe('GET');
    }
});

it('builds the documented path for each typed method', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $client = app(EtoroClient::class);
    $client->userPerformance('demo_trader');
    $client->userLivePortfolio('demo_trader');
    $client->accountPnl(EtoroEnvironment::Real);
    $client->accountPnl(EtoroEnvironment::Demo);

    $urls = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());

    expect($urls)->toContain('https://public-api.etoro.com/api/v1/user-info/people/demo_trader/gain')
        ->toContain('https://public-api.etoro.com/api/v1/user-info/people/demo_trader/portfolio/live')
        ->toContain('https://public-api.etoro.com/api/v1/trading/info/real/pnl')
        ->toContain('https://public-api.etoro.com/api/v1/trading/info/demo/pnl');
});

it('sends the usernames query parameter as a plain scalar value, matching the documented explode=false array serialization', function () {
    Http::fake(['*' => Http::response(['users' => []], 200)]);

    app(EtoroClient::class)->userProfile('demo_trader_one');

    Http::assertSent(function (Request $request) {
        expect($request->url())->toBe('https://public-api.etoro.com/api/v1/user-info/people?usernames=demo_trader_one');

        return true;
    });
});

it('rejects a blank username for every username-accepting method', function (string $method) {
    app(EtoroClient::class)->{$method}('   ');
})->throws(InvalidArgumentException::class)->with([
    'userProfile',
    'userPerformance',
    'userLivePortfolio',
]);

it('URL-encodes a username containing path-altering characters as a single path segment', function () {
    Http::fake(['*' => Http::response(['monthly' => [], 'yearly' => []], 200)]);

    $maliciousUsername = 'evil/user?x=1#frag with space';

    app(EtoroClient::class)->userPerformance($maliciousUsername);

    Http::assertSent(function (Request $request) {
        $encoded = rawurlencode('evil/user?x=1#frag with space');

        expect($request->url())->toBe("https://public-api.etoro.com/api/v1/user-info/people/{$encoded}/gain")
            ->and($request->url())->not->toContain('/user-info/people/evil/user');

        return true;
    });
});

it('generates a fresh x-request-id for every physical HTTP attempt, not just every logical call', function () {
    Http::fakeSequence()
        ->push(['error' => 'boom'], 503)
        ->push(['error' => 'boom'], 503)
        ->push(['gcid' => 1], 200);

    $response = app(EtoroClient::class)->authenticatedUser();

    Http::assertSentCount(3);

    $requestIds = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0]->header('x-request-id')[0]);

    expect($requestIds->unique())->toHaveCount(3)
        ->and($response->requestId)->toBe($requestIds->last());
});

it('disables redirects and treats a 3xx response as an unexpected response without following it', function () {
    Http::fake([
        'https://public-api.etoro.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/steal']),
        'https://evil.example.com/*' => Http::response(['stolen' => true], 200),
    ]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroUnexpectedResponseException to be thrown.');
    } catch (EtoroUnexpectedResponseException $exception) {
        expect($exception->getMessage())->not->toContain('evil.example.com');
    }

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v1/me'
        && $request->header('x-api-key') === ['test-api-key-value']);
});

it('throws a configuration exception when the integration is disabled', function () {
    config(['etoro.enabled' => false]);

    app(EtoroClient::class)->authenticatedUser();
})->throws(EtoroConfigurationException::class);

it('throws a configuration exception when a credential is missing', function () {
    config(['etoro.api_key' => null]);

    app(EtoroClient::class)->authenticatedUser();
})->throws(EtoroConfigurationException::class);

it('does not send a request when configuration is invalid', function () {
    config(['etoro.enabled' => false]);
    Http::fake();

    try {
        app(EtoroClient::class)->authenticatedUser();
    } catch (EtoroConfigurationException) {
        // expected
    }

    Http::assertNothingSent();
});

it('rejects a non-HTTPS base URL and sends no request', function () {
    config(['etoro.base_url' => 'http://public-api.etoro.com']);
    Http::fake();

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroConfigurationException to be thrown.');
    } catch (EtoroConfigurationException) {
        // expected
    }

    Http::assertNothingSent();
});

it('rejects a malformed base URL and sends no request', function () {
    config(['etoro.base_url' => 'not a valid url ://']);
    Http::fake();

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroConfigurationException to be thrown.');
    } catch (EtoroConfigurationException) {
        // expected
    }

    Http::assertNothingSent();
});

it('allows a valid https base URL', function () {
    config(['etoro.base_url' => 'https://public-api.etoro.com']);
    Http::fake(['*' => Http::response(['gcid' => 1, 'scopes' => []], 200)]);

    app(EtoroClient::class)->authenticatedUser();

    Http::assertSentCount(1);
});

it('maps documented HTTP error statuses to EtoroRequestException without retrying', function (int $status, EtoroErrorCategory $category) {
    Http::fake(['*' => Http::response(['error' => 'sentinel-should-not-appear'], $status)]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->category)->toBe($category)
            ->and($exception->httpStatus)->toBe($status)
            ->and($exception->getMessage())->not->toContain('sentinel-should-not-appear');
    }

    Http::assertSentCount(1);
})->with([
    [400, EtoroErrorCategory::Validation],
    [401, EtoroErrorCategory::Authentication],
    [403, EtoroErrorCategory::Authorization],
    [404, EtoroErrorCategory::NotFound],
]);

it('carries the Retry-After value on 429 without retrying automatically', function () {
    Http::fake(['*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '17'])]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->category)->toBe(EtoroErrorCategory::RateLimited)
            ->and($exception->retryAfterSeconds)->toBe(17);
    }

    Http::assertSentCount(1);
});

it('retries a bounded number of times on 5xx and then throws', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 503)]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->category)->toBe(EtoroErrorCategory::ServerError);
    }

    Http::assertSentCount(3);
});

it('succeeds after a transient 5xx is followed by a 2xx', function () {
    Http::fakeSequence()
        ->push(['error' => 'boom'], 503)
        ->push(['gcid' => 1], 200);

    app(EtoroClient::class)->authenticatedUser();

    Http::assertSentCount(2);
});

it('retries a bounded number of times on connection failure and then throws', function () {
    Http::fake(fn () => throw new ConnectionException('simulated connection failure'));

    app(EtoroClient::class)->authenticatedUser();
})->throws(EtoroRequestException::class);

it('falls back to unknown_transport_failure without a curl errno when the cause cannot be determined', function () {
    Http::fake(fn () => throw new ConnectionException('SENTINEL_SHOULD_NOT_LEAK'));

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->transportReason)->toBe('unknown_transport_failure')
            ->and($exception->transportErrno)->toBeNull()
            ->and($exception->getMessage())->not->toContain('SENTINEL_SHOULD_NOT_LEAK');
    }
});

it('normalizes a connection failure into a safe transport category using only curl errno/timing, never the original message', function (int $errno, ?float $connectTime, string $expectedReason) {
    $handlerContext = array_filter(
        ['errno' => $errno, 'connect_time' => $connectTime],
        static fn (mixed $value): bool => $value !== null,
    );

    $original = new GuzzleConnectException(
        'SENTINEL_ORIGINAL_TRANSPORT_MESSAGE_SHOULD_NOT_LEAK',
        new GuzzleRequest('GET', 'https://public-api.etoro.com/api/v1/me'),
        null,
        $handlerContext,
    );

    Http::fake(fn () => throw new ConnectionException($original->getMessage(), 0, $original));

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->transportReason)->toBe($expectedReason)
            ->and($exception->transportErrno)->toBe($errno)
            ->and($exception->getMessage())->not->toContain('SENTINEL_ORIGINAL_TRANSPORT_MESSAGE_SHOULD_NOT_LEAK');
    }
})->with([
    'dns failure (could not resolve host)' => [6, null, 'dns_failure'],
    'tls failure (ssl connect error)' => [35, null, 'tls_failure'],
    'connect timeout (never connected)' => [28, 0.0, 'connect_timeout'],
    'request timeout (connected, then stalled)' => [28, 1.25, 'request_timeout'],
    'connection reset (got nothing)' => [52, null, 'connection_reset'],
    'ambiguous couldnt-connect stays unknown rather than guessed' => [7, null, 'unknown_transport_failure'],
]);

it('throws EtoroUnexpectedResponseException when a 2xx body does not decode to an array', function () {
    Http::fake(['*' => Http::response('not-json-and-not-an-object', 200)]);

    app(EtoroClient::class)->authenticatedUser();
})->throws(EtoroUnexpectedResponseException::class);

it('never leaks configured credential values in exception messages', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 401)]);

    try {
        app(EtoroClient::class)->authenticatedUser();
    } catch (EtoroRequestException $exception) {
        expect($exception->getMessage())
            ->not->toContain('test-api-key-value')
            ->not->toContain('test-user-key-value');
    }
});

it('reports attempt count and duration diagnostics when the first attempt times out, the second is a 503, and the third succeeds', function () {
    $callCount = 0;
    $capturedRequestIds = [];

    // Http::fake() only records completed request/response pairs, not
    // attempts whose stub callback throws — so request IDs are captured
    // directly from every closure invocation instead of Http::recorded().
    Http::fake(function (Request $request) use (&$callCount, &$capturedRequestIds) {
        $callCount++;
        $capturedRequestIds[] = $request->header('x-request-id')[0];

        return match ($callCount) {
            1 => throw new ConnectionException('SENTINEL_SHOULD_NOT_LEAK'),
            2 => Http::response(['error' => 'boom-SENTINEL_SHOULD_NOT_LEAK'], 503),
            default => Http::response(['gcid' => 1, 'scopes' => []], 200),
        };
    });

    $response = app(EtoroClient::class)->authenticatedUser();

    expect($callCount)->toBe(3)
        ->and($response->attemptCount)->toBe(3)
        ->and(array_unique($capturedRequestIds))->toHaveCount(3)
        ->and($response->requestId)->toBe($capturedRequestIds[2])
        ->and($response->totalDurationMs)->toBeGreaterThan(0)
        ->and($response->finalAttemptDurationMs)->toBeGreaterThanOrEqual(0)
        ->and(json_encode($response->payload))->not->toContain('SENTINEL_SHOULD_NOT_LEAK')
        ->and(json_encode($response->payload))->not->toContain('test-api-key-value')
        ->and(json_encode($response->payload))->not->toContain('test-user-key-value');
});

it('carries attempt count and duration diagnostics on a final failure after retries', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 503)]);

    try {
        app(EtoroClient::class)->authenticatedUser();
        $this->fail('Expected EtoroRequestException to be thrown.');
    } catch (EtoroRequestException $exception) {
        expect($exception->attemptCount)->toBe(3)
            ->and($exception->totalDurationMs)->toBeGreaterThan(0)
            ->and($exception->finalAttemptDurationMs)->toBeGreaterThanOrEqual(0);
    }
});

it('captures rate-limit headers when present', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200, [
        'X-RateLimit-Limit' => '45',
        'X-RateLimit-Remaining' => '44',
    ])]);

    $response = app(EtoroClient::class)->authenticatedUser();

    expect($response->rateLimitHeaders)->toBe([
        'X-RateLimit-Limit' => '45',
        'X-RateLimit-Remaining' => '44',
    ]);
});
