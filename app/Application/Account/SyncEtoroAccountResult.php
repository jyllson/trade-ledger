<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Traders\EnrichInstrumentMetadataResult;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;

/**
 * Terminal result of SyncEtoroAccount::handle(). `snapshot` is the current
 * snapshot after a completed sync — newly created when `snapshotCreated`,
 * otherwise the unchanged latest one.
 */
final readonly class SyncEtoroAccountResult
{
    public function __construct(
        public ImportRun $importRun,
        public SyncEtoroAccountStopReason $stopReason,
        public ?AccountSnapshot $snapshot = null,
        public bool $snapshotCreated = false,
        public ?EnrichInstrumentMetadataResult $enrichment = null,
        public ?int $retryAfterSeconds = null,
    ) {}
}
