<?php

namespace App\Etoro\Concerns;

use App\Etoro\Exceptions\EtoroConfigurationException;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Configuration checks and response metadata shared by the read-only
 * EtoroClient and the demo copy-trading EtoroDemoCopyClient (D-050), so
 * both refuse to send credentials under the same conditions.
 */
trait PreparesEtoroRequests
{
    /**
     * Both the legacy `X-RateLimit-*` names and the unprefixed
     * `RateLimit-*` names documented in the current OpenAPI definition.
     *
     * @var list<string>
     */
    private const RATE_LIMIT_HEADERS = [
        'Retry-After',
        'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset',
        'RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset', 'RateLimit-Policy',
    ];

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

    /**
     * ETORO_BASE_URL must be a bare origin: `https://<host>` with an
     * optional trailing '/', an optional explicit port 443, and nothing
     * else — no userinfo, other port, path, query or fragment. Request
     * URLs are built as origin + typed API path, so a path or query in the
     * configuration can never redirect a request to another route.
     *
     * The read-only client passes no pinned host, so a future HTTPS
     * demo/staging host stays configurable. The demo copy client pins the
     * host (EtoroWriteGuard::DEMO_COPY_HOST) and builds its URLs from that
     * constant; the configured value is then only checked, never used.
     *
     * @return string the normalized origin, e.g. `https://public-api.etoro.com`
     *
     * @throws EtoroConfigurationException before anything is sent
     */
    private function validatedApiOrigin(?string $pinnedHost = null): string
    {
        $baseUrl = config('etoro.base_url');

        if (! is_string($baseUrl) || preg_match('/[^\x21-\x7E]|[\\\\@?#%]/', $baseUrl) === 1) {
            throw EtoroConfigurationException::invalidBaseUrl();
        }

        $parts = parse_url($baseUrl);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $parts['host']) !== 1
            || ($pinnedHost !== null && $parts['host'] !== $pinnedHost)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw EtoroConfigurationException::invalidBaseUrl();
        }

        return 'https://'.$parts['host'];
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
}
