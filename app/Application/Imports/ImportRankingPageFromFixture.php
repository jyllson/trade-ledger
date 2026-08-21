<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\FixtureSources\RankingFixtureException;
use App\Etoro\FixtureSources\RankingFixtureSource;
use App\Etoro\Mappers\RankingsMapper;
use App\Etoro\RankingQuery;

/**
 * Orchestrates the fixture-only ranking-page import pipeline: the single
 * canonical offline fixture -> RankingsMapper -> ImportRankingPage. Never
 * references EtoroClient and performs no network request of any kind. Does
 * not catch or suppress any mapping or persistence exception itself —
 * every Throwable from the fixture source, the mapper, or the importer
 * propagates to the caller unchanged.
 */
final class ImportRankingPageFromFixture
{
    public function __construct(
        private readonly RankingFixtureSource $fixtureSource,
        private readonly RankingsMapper $mapper,
        private readonly ImportRankingPage $importRankingPage,
    ) {}

    public function handle(RankingQuery $rankingQuery): ImportRankingPageFromFixtureResult
    {
        $payload = $this->fixtureSource->load();
        $rankingPage = $this->mapper->map($payload);

        // Fail closed BEFORE any importer/DB call: the fixture is a fixed,
        // single-page offline source, so a requested page/pageSize that
        // does not exactly match what the fixture itself actually contains
        // must never be silently substituted with the fixture's real page.
        if (
            $rankingPage->pagination->page !== $rankingQuery->page
            || $rankingPage->pagination->pageSize !== $rankingQuery->pageSize
        ) {
            throw RankingFixtureException::paginationMismatch(
                requestedPage: $rankingQuery->page,
                requestedPageSize: $rankingQuery->pageSize,
                fixturePage: $rankingPage->pagination->page,
                fixturePageSize: $rankingPage->pagination->pageSize,
            );
        }

        $importRun = $this->importRankingPage->handle($rankingPage, $rankingQuery);

        return new ImportRankingPageFromFixtureResult(
            importRun: $importRun,
            fixturePagination: $rankingPage->pagination,
            entryCount: count($rankingPage->entries),
        );
    }
}
