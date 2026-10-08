<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Etoro\EtoroEnvironment;
use RuntimeException;

/**
 * Thrown before any request or ImportRun when an account environment that
 * is not enabled in code (Real, D-051) is asked to sync.
 */
final class AccountSyncNotEnabled extends RuntimeException
{
    public static function for(EtoroEnvironment $environment): self
    {
        return new self(sprintf(
            'Syncing the %s eToro account is disabled in code until the owner accepts the Demo account tracking (PROJECT.md §20, docs/DECISIONS.md D-051).',
            strtoupper($environment->value),
        ));
    }
}
