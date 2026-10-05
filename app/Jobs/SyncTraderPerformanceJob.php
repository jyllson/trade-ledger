<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Traders\SyncTraderPerformance;
use App\Application\Traders\SyncTraderPerformanceStopReason;
use App\Etoro\GainGranularity;
use App\Models\Trader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/**
 * Queued wrapper around SyncTraderPerformance (docs/DECISIONS.md D-034).
 *
 * - Shares the `etoro-api` rate limiter (ETORO_REQUESTS_PER_MINUTE) with
 *   every other queued eToro job, so workers never exceed the app budget.
 * - Unique per trader while queued: re-dispatching the same trader is a
 *   no-op until the pending job runs.
 * - Only a TemporarilyUnavailable outcome (429/5xx/transport) is released
 *   for a later attempt, honouring Retry-After; a private/not-found/mapping
 *   outcome is final and already recorded in its ImportRun.
 */
final class SyncTraderPerformanceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Rate-limited releases count as attempts, so bound by time instead. */
    private const RETRY_WINDOW_HOURS = 6;

    private const DEFAULT_RETRY_SECONDS = 60;

    private const MAX_RETRY_SECONDS = 900;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 3600;

    public function __construct(public Trader $trader) {}

    public function uniqueId(): string
    {
        return (string) $this->trader->id;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('etoro-api')];
    }

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    /**
     * Monthly first, then daily (D-035). Each granularity is its own
     * idempotent sync with its own ImportRun; if either is temporarily
     * unavailable the whole job is released and both re-run later. A final
     * outcome (e.g. private) for monthly skips the daily request.
     */
    public function handle(SyncTraderPerformance $syncTraderPerformance): void
    {
        foreach ([GainGranularity::Monthly, GainGranularity::Daily] as $granularity) {
            $result = $syncTraderPerformance->handle($this->trader, $granularity);

            if ($result->stopReason->isRetryable()) {
                $this->release(min(
                    max($result->retryAfterSeconds ?? self::DEFAULT_RETRY_SECONDS * $this->attempts(), 1),
                    self::MAX_RETRY_SECONDS,
                ));

                return;
            }

            if ($result->stopReason !== SyncTraderPerformanceStopReason::Completed) {
                return;
            }
        }
    }
}
