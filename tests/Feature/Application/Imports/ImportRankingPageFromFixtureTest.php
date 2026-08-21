<?php

use App\Application\Imports\ImportRankingPage;
use App\Application\Imports\ImportRankingPageFromFixture;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\FixtureSources\RankingFixtureException;
use App\Etoro\FixtureSources\RankingFixtureFailureReason;
use App\Etoro\FixtureSources\RankingFixtureSource;
use App\Etoro\Mappers\RankingsMapper;
use App\Etoro\RankingQuery;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Support\Facades\Http;

function importRankingPageFromFixtureUseCase(?RankingFixtureSource $fixtureSource = null): ImportRankingPageFromFixture
{
    return new ImportRankingPageFromFixture(
        $fixtureSource ?? new RankingFixtureSource,
        new RankingsMapper,
        new ImportRankingPage,
    );
}

function importRankingPageFromFixtureCanonicalQuery(int $page = 1, int $pageSize = 3): RankingQuery
{
    return new RankingQuery(period: 'lastYear', page: $page, pageSize: $pageSize, sort: null, country: null);
}

function importRankingPageFromFixtureTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'import_ranking_page_from_fixture_test_');
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    Http::assertNothingSent();
});

// --- Happy path ----------------------------------------------------------

it('imports the canonical fixture end to end with zero failures', function (): void {
    Http::fake();

    $result = importRankingPageFromFixtureUseCase()->handle(importRankingPageFromFixtureCanonicalQuery());

    expect($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->success_count)->toBe(3)
        ->and($result->importRun->failure_count)->toBe(0)
        ->and($result->entryCount)->toBe(3)
        ->and($result->fixturePagination->page)->toBe(1)
        ->and($result->fixturePagination->pageSize)->toBe(3)
        ->and(Trader::query()->count())->toBe(3);
});

// --- Mapping failure -------------------------------------------------------

it('propagates EtoroMappingException uncaught and writes nothing when the fixture payload is malformed', function (): void {
    Http::fake();

    $path = importRankingPageFromFixtureTempFile('{"pagination":{"page":1,"pageSize":3,"totalItems":0,"hasNext":false}}');

    try {
        $useCase = importRankingPageFromFixtureUseCase(new RankingFixtureSource($path));

        expect(fn () => $useCase->handle(importRankingPageFromFixtureCanonicalQuery()))
            ->toThrow(EtoroMappingException::class);

        expect(Trader::query()->count())->toBe(0)
            ->and(ImportRun::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

// --- Pagination contract mismatch (fail closed BEFORE any DB write) --------

it('rejects a page/pageSize mismatch against the fixture before any ImportRun or Trader write', function (): void {
    Http::fake();

    $useCase = importRankingPageFromFixtureUseCase();

    try {
        $useCase->handle(importRankingPageFromFixtureCanonicalQuery(page: 2, pageSize: 3));
        expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
    } catch (RankingFixtureException $exception) {
        expect($exception->reason)->toBe(RankingFixtureFailureReason::PaginationMismatch)
            ->and($exception->getMessage())->toContain('page=2')
            ->and($exception->getMessage())->toContain('page=1');
    }

    expect(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('rejects a pageSize mismatch against the fixture before any ImportRun or Trader write', function (): void {
    Http::fake();

    $useCase = importRankingPageFromFixtureUseCase();

    expect(fn () => $useCase->handle(importRankingPageFromFixtureCanonicalQuery(page: 1, pageSize: 20)))
        ->toThrow(RankingFixtureException::class);

    expect(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

// --- Persistence exception propagates uncaught ------------------------------

it('propagates an unexpected persistence failure uncaught, with ImportRun already recorded per the existing importer contract', function (): void {
    Http::fake();

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    $hasThrown = false;

    ImportRun::saving(function (ImportRun $importRun) use (&$hasThrown): void {
        if (! $hasThrown && $importRun->status !== ImportRunStatus::Running) {
            $hasThrown = true;

            throw new RuntimeException('Simulated ImportRun finalization failure for test purposes.');
        }
    });

    try {
        expect(fn () => importRankingPageFromFixtureUseCase()->handle(importRankingPageFromFixtureCanonicalQuery()))
            ->toThrow(RuntimeException::class);
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    expect(Trader::query()->count())->toBe(0);

    $importRun = ImportRun::query()->latest('id')->firstOrFail();

    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('Simulated ImportRun finalization failure');
});
