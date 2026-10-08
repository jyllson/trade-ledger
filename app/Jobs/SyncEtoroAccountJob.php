<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Account\SyncEtoroAccount;
use App\Application\Imports\FailInterruptedImportRuns;
use App\Etoro\EtoroEnvironment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Queued wrapper around SyncEtoroAccount (docs/DECISIONS.md D-051), same
 * contract as SyncTraderPortfolioJob (D-034, D-038–D-040):
 *
 * - No job-level rate limiter: the eToro transport spends one permit per
 *   HTTP attempt (D-039); a locally exhausted budget is a retryable outcome.
 * - Unique per environment while queued.
 * - Only TemporarilyUnavailable is released for a later attempt, honouring
 *   Retry-After.
 * - Bounded by $timeout (D-040); a killed attempt's still-`running`
 *   ImportRun is closed as `failed` by failed() or the next attempt.
 * - A Real environment never reaches eToro: SyncEtoroAccount throws
 *   AccountSyncNotEnabled first and the job fails.
 */
final class SyncEtoroAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Releases count as attempts, so bound by time instead. */
    private const RETRY_WINDOW_HOURS = 6;

    private const DEFAULT_RETRY_SECONDS = 60;

    private const MAX_RETRY_SECONDS = 900;

    /**
     * Below `retry_after` (90 s, config/queue.php) so a running attempt is
     * never handed to a second worker (D-040). One live Real P&L request
     * once took ~22 s (Milestone 1), well inside this bound.
     */
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public EtoroEnvironment $environment) {}

    public function uniqueId(): string
    {
        return $this->environment->value;
    }

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function handle(SyncEtoroAccount $syncEtoroAccount, FailInterruptedImportRuns $failInterruptedImportRuns): void
    {
        $queueJobUuid = $this->job?->uuid();

        if ($queueJobUuid !== null) {
            $failInterruptedImportRuns->handle(SyncEtoroAccount::TYPE, $queueJobUuid, 'attempt_interrupted');
        }

        $result = $syncEtoroAccount->handle($this->environment, $queueJobUuid);

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

        app(FailInterruptedImportRuns::class)->handle(SyncEtoroAccount::TYPE, $queueJobUuid, match (true) {
            $exception instanceof TimeoutExceededException => 'timeout',
            $exception instanceof MaxAttemptsExceededException => 'max_attempts',
            default => 'job_failed',
        });
    }
}
