<?php

namespace App\Etoro;

use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use App\Etoro\Exceptions\EtoroWriteModeNotAllowedException;

/**
 * Fail-closed guard for eToro write access (PROJECT.md §2, §17; D-050).
 *
 * The application is read-only except for copying traders on the DEMO
 * account:
 *
 * - Generic write mode cannot be enabled through configuration:
 *   allowsWrite() is hard-coded to false, and ensureReadOnly() refuses to
 *   let the application boot when ETORO_ALLOW_WRITE has been switched on
 *   by mistake. ETORO_ALLOW_WRITE never opens anything.
 * - The single exception is an exact allow-list of the documented demo
 *   copy-trading routes (method + path), and only while ETORO_ALLOW_DEMO_COPY
 *   is strictly true. Every path containing `/real` and every route that is
 *   not on the list is refused regardless of any flag.
 */
class EtoroWriteGuard
{
    /**
     * The only host a demo copy request may be sent to. A code constant,
     * never configuration: EtoroDemoCopyClient builds every URL from it.
     */
    public const DEMO_COPY_HOST = 'public-api.etoro.com';

    public const DEMO_COPY_ORIGIN = 'https://'.self::DEMO_COPY_HOST;

    public const DEMO_COPY_PATH = '/api/v2/trading/copy/demo';

    public const DEMO_COPY_ELIGIBILITY_PATH = '/api/v2/trading/copy/demo/eligibility';

    public const DEMO_COPY_CLOSE_PATH = '/api/v2/trading/copy/demo/close';

    /**
     * Caller-generated referenceID: URL-safe, no '/', max 35 characters
     * (OpenAPI `CopyTradingRegisterRequest.referenceID`). Narrowed to
     * letters, digits, '-' and '_' so a path segment can never contain
     * '.', '%', '?' or '#'.
     */
    public const REFERENCE_ID_PATTERN = '/\A[A-Za-z0-9_-]{1,35}\z/';

    /**
     * Exact (method, path) pairs that may send a non-GET request. The
     * documented DELETE close binding is deliberately absent: the JSON-body
     * POST close binding has identical semantics, so allowing both would
     * only widen the surface.
     *
     * @var list<string>
     */
    private const DEMO_COPY_WRITE_ROUTES = [
        'POST '.self::DEMO_COPY_PATH,
        'POST '.self::DEMO_COPY_ELIGIBILITY_PATH,
        'POST '.self::DEMO_COPY_CLOSE_PATH,
    ];

    public function allowsWrite(): bool
    {
        return false;
    }

    public function ensureReadOnly(): void
    {
        if ((bool) config('etoro.allow_write') !== false) {
            throw EtoroWriteModeNotAllowedException::make();
        }
    }

    /**
     * Strictly true only — a string such as "1" or "yes" in a cached or
     * hand-edited config keeps demo copy disabled (fail closed).
     */
    public function allowsDemoCopy(): bool
    {
        return config('etoro.allow_demo_copy') === true;
    }

    /**
     * Checked by EtoroDemoCopyClient immediately before every request it
     * sends, including the read-only outcome poll, against the FINAL URL
     * that will be sent: https, exactly DEMO_COPY_HOST, no userinfo, port,
     * query or fragment, and a path without encoded characters, empty or
     * dot segments. Only then is (method, path) matched against the exact
     * allow-list. A '/real' anywhere in the URL is refused first, so a
     * real-account target is reported as such even while the flag is off.
     */
    public function ensureDemoCopyRequestAllowed(string $method, string $url): void
    {
        $method = strtoupper($method);
        $parts = parse_url($url);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';

        if (str_contains(strtolower($url), '/real')) {
            throw EtoroDemoCopyNotAllowedException::realAccount($method, $path);
        }

        if (
            ! is_array($parts)
            || preg_match('/[^\x21-\x7E]|[\\\\@]/', $url) === 1
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== self::DEMO_COPY_HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || $url !== self::DEMO_COPY_ORIGIN.$path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '')
        ) {
            throw EtoroDemoCopyNotAllowedException::untrustedTarget($method);
        }

        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#') || ! $this->isNormalizedPath($path) || ! $this->isAllowListedDemoCopyRoute($method, $path)) {
            throw EtoroDemoCopyNotAllowedException::routeNotAllowListed($method, $path);
        }

        if (! $this->allowsDemoCopy()) {
            throw EtoroDemoCopyNotAllowedException::disabled();
        }
    }

    /**
     * Already in its final form: no percent-encoding, no empty ('//'), '.'
     * or '..' segment — so the server cannot resolve it to another route.
     */
    private function isNormalizedPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_contains($path, '%')) {
            return false;
        }

        $segments = explode('/', substr($path, 1));
        $last = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            if (in_array($segment, ['.', '..'], true) || ($segment === '' && $index !== $last)) {
                return false;
            }
        }

        return true;
    }

    private function isAllowListedDemoCopyRoute(string $method, string $path): bool
    {
        if (in_array($method.' '.$path, self::DEMO_COPY_WRITE_ROUTES, true)) {
            return true;
        }

        if ($method !== 'GET' || ! str_starts_with($path, self::DEMO_COPY_PATH.'/')) {
            return false;
        }

        $referenceId = substr($path, strlen(self::DEMO_COPY_PATH.'/'));

        return preg_match(self::REFERENCE_ID_PATTERN, $referenceId) === 1
            && ! in_array($referenceId, ['eligibility', 'close'], true);
    }
}
