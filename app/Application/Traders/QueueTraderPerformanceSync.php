<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Jobs\SyncTraderPerformanceJob;
use App\Models\Trader;

/**
 * The only entry point UI/console code uses to request a performance sync:
 * queues SyncTraderPerformanceJob (unique per trader, rate limited). Returns
 * false without queueing when the eToro integration is disabled.
 */
final class QueueTraderPerformanceSync
{
    public function handle(Trader $trader): bool
    {
        if (! config('etoro.enabled')) {
            return false;
        }

        SyncTraderPerformanceJob::dispatch($trader);

        return true;
    }
}
