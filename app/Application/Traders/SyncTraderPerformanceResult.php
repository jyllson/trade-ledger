<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\ImportRun;

/**
 * Terminal result of SyncTraderPerformance::handle(). `retryAfterSeconds`
 * is the API's Retry-After hint when the stop reason is retryable.
 */
final readonly class SyncTraderPerformanceResult
{
    public function __construct(
        public ImportRun $importRun,
        public SyncTraderPerformanceStopReason $stopReason,
        public int $storedPointCount,
        public ?int $retryAfterSeconds = null,
    ) {}
}
