<?php

namespace App\Etoro;

use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
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
    /**
     * @var list<string>
     */
    private const RATE_LIMIT_HEADERS = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    /**
     * 1 initial attempt + 2 retries, for connection failures and 5xx only.
     */
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Factory $http) {}

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

        $url = rtrim((string) config('etoro.base_url'), '/').$path;
        $startedAt = microtime(true);
        $response = null;
        $requestId = null;
        $attempt = 0;
        $finalAttemptDurationMs = 0.0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
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

    private function ensureConfigured(): void
    {
        if (! config('etoro.enabled')) {
            throw EtoroConfigurationException::disabled();
        }

        if (blank(config('etoro.api_key'))) {
            throw EtoroConfigurationException::missingCredential('ETORO_API_KEY');
        }

        if (blank(config('etoro.user_key'))) {
            throw EtoroConfigurationException::missingCredential('ETORO_USER_KEY');
        }

        $this->ensureHttpsBaseUrl();
    }

    /**
     * Credential headers must never be sent to a non-HTTPS or malformed
     * endpoint. Validates only the scheme (must be https) and that a host
     * is present — deliberately not tied to one hard-coded hostname, so a
     * future demo/staging HTTPS endpoint remains configurable via
     * ETORO_BASE_URL without a code change.
     */
    private function ensureHttpsBaseUrl(): void
    {
        $baseUrl = (string) config('etoro.base_url');
        $parts = parse_url($baseUrl);

        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || $parts['scheme'] !== 'https'
            || $parts['host'] === ''
        ) {
            throw EtoroConfigurationException::invalidBaseUrl();
        }
    }

    /**
     * Maps a connection failure to one of a small set of normalized, safe
     * categories using only objective transport metadata (curl error number
     * and connect timing) — never the original exception message, request
     * URL, or payload. When the cause cannot be reliably determined from
     * that metadata alone, this deliberately falls back to
     * 'unknown_transport_failure' rather than guessing.
     *
     * @return array{0: string, 1: ?int}
     */
    private function diagnoseTransportFailure(ConnectionException $exception): array
    {
        $previous = $exception->getPrevious();

        $context = match (true) {
            $previous instanceof GuzzleConnectException => $previous->getHandlerContext(),
            $previous instanceof GuzzleRequestException => $previous->getHandlerContext(),
            default => [],
        };

        $errno = is_int($context['errno'] ?? null) ? $context['errno'] : null;

        if ($errno === null) {
            return ['unknown_transport_failure', null];
        }

        // Known curl error numbers (ext-curl CURLE_* constants), grouped by
        // cause. Anything not in one of these known clusters is reported as
        // unknown_transport_failure rather than assigned a guessed category.
        $dnsErrnos = [5, 6]; // COULDNT_RESOLVE_PROXY, COULDNT_RESOLVE_HOST
        $tlsErrnos = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91]; // SSL/TLS cluster
        $resetErrnos = [52, 55, 56]; // GOT_NOTHING, SEND_ERROR, RECV_ERROR

        $reason = match (true) {
            in_array($errno, $dnsErrnos, true) => 'dns_failure',
            in_array($errno, $tlsErrnos, true) => 'tls_failure',
            in_array($errno, $resetErrnos, true) => 'connection_reset',
            $errno === 28 => $this->isConnectPhaseTimeout($context) ? 'connect_timeout' : 'request_timeout', // OPERATION_TIMEDOUT
            default => 'unknown_transport_failure',
        };

        return [$reason, $errno];
    }

    /**
     * curl's 'connect_time' handler context reports how long the connect
     * phase took. If it never progressed past 0, the connection itself
     * never completed — a connect-phase timeout. Otherwise, the connection
     * was established and the overall/transfer timeout was hit later.
     *
     * @param  array<string, mixed>  $context
     */
    private function isConnectPhaseTimeout(array $context): bool
    {
        $connectTime = $context['connect_time'] ?? null;

        return ! is_float($connectTime) || $connectTime <= 0.0;
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
            rateLimitLimit: $rateLimitHeaders['X-RateLimit-Limit'] ?? null,
            rateLimitRemaining: $rateLimitHeaders['X-RateLimit-Remaining'] ?? null,
            attemptCount: $attemptCount,
            totalDurationMs: $totalDurationMs,
            finalAttemptDurationMs: $finalAttemptDurationMs,
        );
    }

    /**
     * @return array<string, string>
     */
    private function extractRateLimitHeaders(Response $response): array
    {
        $headers = [];

        foreach (self::RATE_LIMIT_HEADERS as $header) {
            $value = $response->header($header);

            if ($value !== '') {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }
}
