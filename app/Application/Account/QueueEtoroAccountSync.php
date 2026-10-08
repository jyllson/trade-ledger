<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Etoro\EtoroEnvironment;
use App\Jobs\SyncEtoroAccountJob;

/**
 * The only entry point UI/console code uses to request an account sync:
 * queues SyncEtoroAccountJob (unique per environment). Returns false
 * without queueing when the eToro integration is disabled.
 */
final class QueueEtoroAccountSync
{
    /**
     * @throws AccountSyncNotEnabled for an environment not enabled in code (Real)
     */
    public function handle(EtoroEnvironment $environment): bool
    {
        AccountSyncEnvironment::assertEnabled($environment);

        if (! config('etoro.enabled')) {
            return false;
        }

        SyncEtoroAccountJob::dispatch($environment);

        return true;
    }
}
