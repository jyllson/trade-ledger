<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Models\ImportRun;

/**
 * Aggregate result of DiscoverEtoroTraders::handle(). Carries the finalized
 * `rankings_discovery` aggregate ImportRun plus run-level bookkeeping —
 * never per-row identity data (no RankingPage/RankingEntry, no cid/
 * username).
 */
final readonly class DiscoverEtoroTradersResult
{
    /**
     * @param  list<int>  $childImportRunIds
     */
    public function __construct(
        public ImportRun $importRun,
        public DiscoverEtoroTradersStopReason $stopReason,
        public int $pagesFetched,
        public array $childImportRunIds,
    ) {}
}
