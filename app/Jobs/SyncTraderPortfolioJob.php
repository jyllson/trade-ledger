<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Imports\FailInterruptedImportRuns;
use App\Application\Traders\SyncTraderPortfolio;
use App\Models\Trader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Queued wrapper around SyncTraderPortfolio (docs/DECISIONS.md D-038), same
 * contract as SyncTraderPerformanceJob (D-034):
 *
 * - No job-level rate limiter: the eToro transport spends one `etoro-api` /
 *   `etoro-market-data` permit per HTTP attempt (D-039); a locally
 *   exhausted budget surfaces as a retryable outcome and releases the job.
 * - Unique per trader while queued.
 * - Only a TemporarilyUnavailable outcome of the portfolio request is
 *   released for a later attempt, honouring Retry-After. Incomplete
 *   instrument metadata is not retried here — the next import asks again.
 * - Bounded by $timeout (D-040); a killed attempt's still-`running`
 *   ImportRun is closed as `failed` by failed() or the next attempt.
 */
final class SyncTraderPortfolioJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Releases count as attempts, so bound by time instead. */
    private const RETRY_WINDOW_HOURS = 6;

    private const DEFAULT_RETRY_SECONDS = 60;

    private const MAX_RETRY_SECONDS = 900;

    /**
     * Below `retry_after` (90 s, config/queue.php) so a running attempt is
     * never handed to a second worker; overrides the worker's 60 s (D-040).
     */
    public int $timeout = 80;

    /**
     * A timed-out attempt fails instead of being retried within
     * retryUntil(): a hanging eToro endpoint would otherwise be re-run every
     * `retry_after` seconds for hours. failed() closes its ImportRuns.
     */
    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 3600;

    public function __construct(public Trader $trader) {}

    public function uniqueId(): string
    {
        return (string) $this->trader->id;
    }

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function handle(SyncTraderPortfolio $syncTraderPortfolio, FailInterruptedImportRuns $failInterruptedImportRuns): void
    {
        $queueJobUuid = $this->job?->uuid();

        if ($queueJobUuid !== null) {
            // Runs a previous, hard-killed attempt of this job left `running`.
            $failInterruptedImportRuns->handle(SyncTraderPortfolio::TYPE, $queueJobUuid, 'attempt_interrupted');
        }

        $result = $syncTraderPortfolio->handle($this->trader, $queueJobUuid);

        if ($result->stopReason->isRetryable()) {
            $this->release(min(
                max($result->retryAfterSeconds ?? self::DEFAULT_RETRY_SECONDS * $this->attempts(), 1),
                self::MAX_RETRY_SECONDS,
            ));
        }
    }

    /**
     * Also runs from the worker's timeout handler (TimeoutExceededException)
     * and for MaxAttemptsExceededException once retryUntil() has passed.
     */
    public function failed(?Throwable $exception): void
    {
        $queueJobUuid = $this->job?->uuid();

        if ($queueJobUuid === null) {
            return;
        }

        app(FailInterruptedImportRuns::class)->handle(SyncTraderPortfolio::TYPE, $queueJobUuid, match (true) {
            $exception instanceof TimeoutExceededException => 'timeout',
            $exception instanceof MaxAttemptsExceededException => 'max_attempts',
            default => 'job_failed',
        });
    }
}
