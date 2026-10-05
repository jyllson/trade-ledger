<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Models\ImportRun;
use App\Models\ImportRunStatus;

/**
 * Closes ImportRuns a queued job opened but never finalized (docs/DECISIONS.md
 * D-040): the worker killed the attempt (timeout, SIGKILL, OOM) before the use
 * case could save its outcome. Runs are matched by the queue job UUID the use
 * case stored in `metadata.queue_job_uuid` — stable across releases/retries of
 * one queued job, distinct from every other job or synchronous run — and only
 * while still `running`, so a finalized run is never touched.
 */
final class FailInterruptedImportRuns
{
    public const STOP_REASON = 'interrupted';

    /**
     * @param  string  $interruption  sanitized cause, e.g. `timeout`; never an exception message
     * @return int number of runs closed
     */
    public function handle(string $type, string $queueJobUuid, string $interruption): int
    {
        $importRuns = ImportRun::query()
            ->where('type', $type)
            ->where('status', ImportRunStatus::Running)
            ->where('metadata->queue_job_uuid', $queueJobUuid)
            ->get();

        foreach ($importRuns as $importRun) {
            $importRun->forceFill([
                'status' => ImportRunStatus::Failed,
                'failure_count' => 1,
                'finished_at' => now(),
                'error_summary' => 'Queued sync was interrupted before it could record its outcome.',
                'metadata' => array_merge($importRun->metadata ?? [], [
                    'stop_reason' => self::STOP_REASON,
                    'interruption' => $interruption,
                ]),
            ])->save();
        }

        return $importRuns->count();
    }
}
