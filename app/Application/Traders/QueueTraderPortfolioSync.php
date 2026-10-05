<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Jobs\SyncTraderPortfolioJob;
use App\Models\Trader;

/**
 * The only entry point UI/console code uses to request a portfolio sync:
 * queues SyncTraderPortfolioJob (unique per trader, rate limited). Returns
 * false without queueing when the eToro integration is disabled.
 */
final class QueueTraderPortfolioSync
{
    public function handle(Trader $trader): bool
    {
        if (! config('etoro.enabled')) {
            return false;
        }

        SyncTraderPortfolioJob::dispatch($trader);

        return true;
    }
}
