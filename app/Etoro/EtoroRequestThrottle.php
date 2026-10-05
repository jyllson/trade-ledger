<?php

declare(strict_types=1);

namespace App\Etoro;

use App\Etoro\Exceptions\EtoroRequestException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-HTTP-attempt budget for every eToro request (docs/DECISIONS.md
 * D-039). EtoroClient asks for one permit before EVERY attempt, including
 * its own transport/5xx retries, so the budget holds identically for queued
 * jobs, synchronous `--now` commands and diagnostics.
 *
 * Two buckets: market-data endpoints have their own eToro quota (120/60 s,
 * D-037); everything else shares the default quota (60/60 s, D-032) through
 * the named `etoro-api` limiter (ETORO_REQUESTS_PER_MINUTE).
 *
 * Never blocks: when the budget is exhausted the attempt is refused at once
 * with a RateLimited EtoroRequestException (no HTTP status, Retry-After =
 * the limiter's remaining window), which every sync use case already treats
 * as temporarily unavailable — queued jobs release themselves, web actions
 * and `--now` commands report it immediately. Waiting inside the transport
 * would make a job's or web request's duration depend on other callers'
 * traffic and could outlive the worker timeout / `retry_after`.
 */
class EtoroRequestThrottle
{
    public const DEFAULT_LIMITER = 'etoro-api';

    public const MARKET_DATA_LIMITER = 'etoro-market-data';

    private const MARKET_DATA_PATH_PREFIX = '/api/v1/market-data/';

    /**
     * @param  int  $attemptsAlreadySent  HTTP attempts of the same call that
     *                                    were already sent (retries), so the
     *                                    refusal reports the real request count
     */
    public function acquire(string $path, int $attemptsAlreadySent = 0, ?string $lastRequestId = null): void
    {
        $limiterName = $this->limiterFor($path);
        $limit = $this->limit($limiterName);
        $key = $limiterName.':'.$limit->key;

        if (! RateLimiter::attempt($key, $limit->maxAttempts, static fn (): bool => true, $limit->decaySeconds)) {
            throw EtoroRequestException::localBudgetExhausted(
                max(1, RateLimiter::availableIn($key)),
                $attemptsAlreadySent,
                $lastRequestId,
            );
        }
    }

    public function limiterFor(string $path): string
    {
        return str_starts_with($path, self::MARKET_DATA_PATH_PREFIX)
            ? self::MARKET_DATA_LIMITER
            : self::DEFAULT_LIMITER;
    }

    private function limit(string $limiterName): Limit
    {
        $limiter = RateLimiter::limiter($limiterName);
        $limit = $limiter !== null ? $limiter() : null;

        // Fail closed: a missing/misconfigured limiter means one request per minute.
        return $limit instanceof Limit ? $limit : Limit::perMinute(1);
    }
}
