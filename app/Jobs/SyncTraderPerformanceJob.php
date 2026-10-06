<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Imports\FailInterruptedImportRuns;
use App\Application\Traders\SyncTraderPerformance;
use App\Application\Traders\SyncTraderPerformanceStopReason;
use App\Etoro\GainGranularity;
use App\Models\Trader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Queued wrapper around SyncTraderPerformance (docs/DECISIONS.md D-034).
 *
 * - No job-level rate limiter: the eToro transport spends one `etoro-api`
 *   permit (ETORO_REQUESTS_PER_MINUTE) per HTTP attempt (D-039), so queued
 *   and synchronous runs share one budget; a locally exhausted budget
 *   surfaces as a retryable outcome and releases the job.
 * - Unique per trader while queued: re-dispatching the same trader is a
 *   no-op until the pending job runs.
 * - Only a TemporarilyUnavailable outcome (429/5xx/transport) is released
 *   for a later attempt, honouring Retry-After; a private/not-found/mapping
 *   outcome is final and already recorded in its ImportRun.
 * - Bounded by $timeout (D-040); a killed attempt's still-`running`
 *   ImportRuns are closed as `failed` by failed() or the next attempt.
 */
final class SyncTraderPerformanceJob implements ShouldBeUnique, ShouldQueue
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

    /**
     * Monthly first, then daily (D-035). Each granularity is its own
     * idempotent sync with its own ImportRun; if either is temporarily
     * unavailable the whole job is released and both re-run later. A final
     * outcome (e.g. private) for monthly skips the daily request.
     */
    public function handle(SyncTraderPerformance $syncTraderPerformance, FailInterruptedImportRuns $failInterruptedImportRuns): void
    {
        $queueJobUuid = $this->job?->uuid();

        if ($queueJobUuid !== null) {
            // Runs a previous, hard-killed attempt of this job left `running`.
            $failInterruptedImportRuns->handle(SyncTraderPerformance::TYPE, $queueJobUuid, 'attempt_interrupted');
        }

        foreach ([GainGranularity::Monthly, GainGranularity::Daily] as $granularity) {
            $result = $syncTraderPerformance->handle($this->trader, $granularity, $queueJobUuid);

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

        app(FailInterruptedImportRuns::class)->handle(SyncTraderPerformance::TYPE, $queueJobUuid, match (true) {
            $exception instanceof TimeoutExceededException => 'timeout',
            $exception instanceof MaxAttemptsExceededException => 'max_attempts',
            default => 'job_failed',
        });
    }
}
