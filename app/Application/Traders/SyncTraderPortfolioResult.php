<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\ImportRun;
use App\Models\PortfolioSnapshot;

/**
 * Terminal result of SyncTraderPortfolio::handle(). `snapshot` is the
 * trader's current snapshot after a completed sync — newly created when
 * `snapshotCreated`, otherwise the unchanged latest one. `enrichment` is
 * null when the sync stopped before a snapshot was stored.
 */
final readonly class SyncTraderPortfolioResult
{
    public function __construct(
        public ImportRun $importRun,
        public SyncTraderPortfolioStopReason $stopReason,
        public ?PortfolioSnapshot $snapshot = null,
        public bool $snapshotCreated = false,
        public ?EnrichInstrumentMetadataResult $enrichment = null,
        public ?int $retryAfterSeconds = null,
    ) {}
}
