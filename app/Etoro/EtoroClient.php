<?php

namespace App\Etoro;

use App\Etoro\Exceptions\EtoroConfigurationException;
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

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $requestId = (string) Str::uuid();

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
            } catch (ConnectionException) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw EtoroRequestException::connectionFailed($requestId);
                }

                $this->waitBeforeRetry($attempt);

                continue;
            }

            if ($response->status() >= 300 && $response->status() < 400) {
                throw EtoroUnexpectedResponseException::make(
                    $response->status(),
                    $requestId,
                    'unexpected redirect response (redirects are disabled for credentialed requests)',
                );
            }

            if ($response->serverError() && $attempt < self::MAX_ATTEMPTS) {
                $this->waitBeforeRetry($attempt);

                continue;
            }

            break;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000;

        if (! $response->successful()) {
            throw $this->exceptionForStatus($response, $requestId);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw EtoroUnexpectedResponseException::make(
                $response->status(),
                $requestId,
                'response body did not decode to a JSON object/array',
            );
        }

        return new EtoroApiResponse(
            payload: $payload,
            status: $response->status(),
            requestId: $requestId,
            durationMs: $durationMs,
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
    }

    private function waitBeforeRetry(int $attempt): void
    {
        $backoffMs = 200 * $attempt;
        $jitterMs = random_int(0, 150);

        Sleep::usleep(($backoffMs + $jitterMs) * 1000);
    }

    private function exceptionForStatus(Response $response, string $requestId): EtoroRequestException
    {
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
