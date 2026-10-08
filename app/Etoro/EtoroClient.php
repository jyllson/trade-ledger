<?php

namespace App\Etoro;

use App\Etoro\Concerns\PreparesEtoroRequests;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read-only eToro Public API client. Only typed, GET-based public methods
 * are exposed — no public generic request/send method, and no write or
 * trading capability exists anywhere in this class (PROJECT.md §10, §17).
 */
class EtoroClient
{
    use PreparesEtoroRequests;

    /**
     * 1 initial attempt + 2 retries, for connection failures and 5xx only.
     */
    private const MAX_ATTEMPTS = 3;

    public const MAX_INSTRUMENT_IDS = 100;

    public function __construct(
        private readonly Factory $http,
        private readonly EtoroRequestThrottle $throttle = new EtoroRequestThrottle,
    ) {}

    public function authenticatedUser(): EtoroApiResponse
    {
        return $this->get('/api/v1/me');
    }

    public function rankings(RankingQuery $query): EtoroApiResponse
    {
        return $this->get('/api/v2/portfolios/rankings', $query->toQueryArray());
    }

    /**
     * The `usernames` query parameter is documented (OpenAPI) as
     * `type: array, items: string, explode: false` — i.e. comma-separated.
     * A single-element list serializes identically to the bare scalar, which
     * is what this method (single username) sends. If this method is ever
     * extended to accept multiple usernames, join them with commas rather
     * than relying on PHP array/bracket query serialization.
     */
    public function userProfile(string $username): EtoroApiResponse
    {
        $this->assertUsernameProvided($username);

        return $this->get('/api/v1/user-info/people', ['usernames' => $username]);
    }

    public function userPerformance(string $username): EtoroApiResponse
    {
        $this->assertUsernameProvided($username);

        return $this->get('/api/v1/user-info/people/'.$this->pathSegment($username).'/gain');
    }

    /**
     * Documented v2 gain time-series (OpenAPI `getGainHistory`). Unlike the
     * v1 `/gain` endpoint, gain values here are documented as decimal
     * fractions (0.06 = 6%). `$count` is the documented optional 1..1000
     * limit; date-range parameters are intentionally not exposed until a
     * consumer needs them.
     */
    public function userGainHistory(string $username, GainGranularity $granularity, ?int $count = null): EtoroApiResponse
    {
        $this->assertUsernameProvided($username);

        if ($count !== null && ($count < 1 || $count > 1000)) {
            throw new InvalidArgumentException('Gain history count must be between 1 and 1000.');
        }

        return $this->get(
            '/api/v2/portfolios/'.$this->pathSegment($username).'/gain/'.$granularity->value,
            $count !== null ? ['count' => $count] : [],
        );
    }

    /**
     * Documented market-data instrument display data (OpenAPI
     * `getMarketDataInstruments`). `instrumentIds` is documented as an
     * `explode: false` array, i.e. comma-separated. Callers batch; at most
     * MAX_INSTRUMENT_IDS ids per request keeps the URL bounded.
     *
     * Typed loosely on purpose: every element is checked at runtime, since
     * the ids originate from API payloads.
     *
     * @param  array<int, mixed>  $instrumentIds
     */
    public function instrumentDisplayData(array $instrumentIds): EtoroApiResponse
    {
        if ($instrumentIds === [] || count($instrumentIds) > self::MAX_INSTRUMENT_IDS) {
            throw new InvalidArgumentException('Instrument display data needs between 1 and '.self::MAX_INSTRUMENT_IDS.' instrument ids.');
        }

        foreach ($instrumentIds as $instrumentId) {
            if (! is_int($instrumentId) || $instrumentId < 1) {
                throw new InvalidArgumentException('Instrument ids must be positive integers.');
            }
        }

        return $this->get('/api/v1/market-data/instruments', ['instrumentIds' => implode(',', $instrumentIds)]);
    }

    /**
     * Documented instrument type catalogue (OpenAPI
     * `getMarketDataInstrumentTypes`), e.g. Stocks, ETF, Crypto.
     */
    public function instrumentTypes(): EtoroApiResponse
    {
        return $this->get('/api/v1/market-data/instrument-types');
    }

    public function userLivePortfolio(string $username): EtoroApiResponse
    {
        $this->assertUsernameProvided($username);

        return $this->get('/api/v1/user-info/people/'.$this->pathSegment($username).'/portfolio/live');
    }

    public function accountPnl(EtoroEnvironment $environment): EtoroApiResponse
    {
        return $this->get("/api/v1/trading/info/{$environment->value}/pnl");
    }

    /**
     * Private, GET-only infrastructure helper (D-011). Never exposed
     * publicly, never accepts an HTTP method — it can only ever send GET.
     * Redirects are explicitly disabled: a request carrying credential
     * headers must never be silently re-sent to a different host.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): EtoroApiResponse
    {
        $this->ensureConfigured();

        $url = $this->validatedApiOrigin().$path;
        $startedAt = microtime(true);
        $response = null;
        $requestId = null;
        $attempt = 0;
        $finalAttemptDurationMs = 0.0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // One permit per HTTP attempt, retries included (D-039). A refused
            // retry still reports the attempts already sent.
            $this->throttle->acquire($path, $attempt - 1, $requestId);

            $requestId = (string) Str::uuid();
            $attemptStartedAt = microtime(true);

            try {
                $response = $this->http
                    ->timeout((int) config('etoro.timeout_seconds'))
                    ->connectTimeout((int) config('etoro.connect_timeout_seconds'))
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders([
                        'x-request-id' => $requestId,
                        'x-api-key' => (string) config('etoro.api_key'),
                        'x-user-key' => (string) config('etoro.user_key'),
                    ])
                    ->get($url, $query);
            } catch (ConnectionException $exception) {
                $finalAttemptDurationMs = (microtime(true) - $attemptStartedAt) * 1000;

                if ($attempt === self::MAX_ATTEMPTS) {
                    [$transportReason, $transportErrno] = $this->diagnoseTransportFailure($exception);
                    $totalDurationMs = (microtime(true) - $startedAt) * 1000;

                    throw EtoroRequestException::connectionFailed(
                        $requestId,
                        $transportReason,
                        $transportErrno,
                        attemptCount: $attempt,
                        totalDurationMs: $totalDurationMs,
                        finalAttemptDurationMs: $finalAttemptDurationMs,
                    );
                }

                $this->waitBeforeRetry($attempt);

                continue;
            }

            $finalAttemptDurationMs = (microtime(true) - $attemptStartedAt) * 1000;

            if ($response->status() >= 300 && $response->status() < 400) {
                throw EtoroUnexpectedResponseException::make(
                    $response->status(),
                    $requestId,
                    'unexpected redirect response (redirects are disabled for credentialed requests)',
                    attemptCount: $attempt,
                );
            }

            if ($response->serverError() && $attempt < self::MAX_ATTEMPTS) {
                $this->waitBeforeRetry($attempt);

                continue;
            }

            break;
        }

        $totalDurationMs = (microtime(true) - $startedAt) * 1000;

        if (! $response->successful()) {
            throw $this->exceptionForStatus($response, $requestId, $attempt, $totalDurationMs, $finalAttemptDurationMs);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw EtoroUnexpectedResponseException::make(
                $response->status(),
                $requestId,
                'response body did not decode to a JSON object/array',
                attemptCount: $attempt,
            );
        }

        return new EtoroApiResponse(
            payload: $payload,
            status: $response->status(),
            requestId: $requestId,
            attemptCount: $attempt,
            totalDurationMs: $totalDurationMs,
            finalAttemptDurationMs: $finalAttemptDurationMs,
            rateLimitHeaders: $this->extractRateLimitHeaders($response),
        );
    }

    private function assertUsernameProvided(string $username): void
    {
        if (trim($username) === '') {
            throw new InvalidArgumentException('A username is required and must not be blank.');
        }
    }

    /**
     * Encodes a value as a single, safe URL path segment so that '/', '?',
     * '#', spaces, etc. cannot alter the request's endpoint path.
     */
    private function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        $backoffMs = 200 * $attempt;
        $jitterMs = random_int(0, 150);

        Sleep::usleep(($backoffMs + $jitterMs) * 1000);
    }

    private function exceptionForStatus(
        Response $response,
        string $requestId,
        int $attemptCount,
        float $totalDurationMs,
        float $finalAttemptDurationMs,
    ): EtoroRequestException {
        $status = $response->status();
        $retryAfterHeader = $response->header('Retry-After');
        $retryAfter = $retryAfterHeader !== '' ? (int) $retryAfterHeader : null;
        $rateLimitHeaders = $this->extractRateLimitHeaders($response);

        $category = match (true) {
            $status === 400 => EtoroErrorCategory::Validation,
            $status === 401 => EtoroErrorCategory::Authentication,
            $status === 403 => EtoroErrorCategory::Authorization,
            $status === 404 => EtoroErrorCategory::NotFound,
            $status === 429 => EtoroErrorCategory::RateLimited,
            default => EtoroErrorCategory::ServerError,
        };

        return EtoroRequestException::fromStatus(
            category: $category,
            httpStatus: $status,
            requestId: $requestId,
            retryAfterSeconds: $retryAfter,
            rateLimitLimit: $rateLimitHeaders['X-RateLimit-Limit'] ?? $rateLimitHeaders['RateLimit-Limit'] ?? null,
            rateLimitRemaining: $rateLimitHeaders['X-RateLimit-Remaining'] ?? $rateLimitHeaders['RateLimit-Remaining'] ?? null,
            attemptCount: $attemptCount,
            totalDurationMs: $totalDurationMs,
            finalAttemptDurationMs: $finalAttemptDurationMs,
        );
    }
}
