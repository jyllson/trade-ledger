<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\Data\RankingPagination;
use App\Models\ImportRun;

/**
 * Aggregate result of ImportRankingPageFromFixture::handle(). Carries only
 * the persisted ImportRun plus the fixture's own mapped pagination and
 * entry count — never per-row identity data (no RankingPage/RankingEntry,
 * no cid/username).
 */
final readonly class ImportRankingPageFromFixtureResult
{
    public function __construct(
        public ImportRun $importRun,
        public RankingPagination $fixturePagination,
        public int $entryCount,
    ) {}
}
