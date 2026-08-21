<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Imports\ImportRankingPageFromFixture;
use App\Application\Imports\ImportRankingPageFromFixtureResult;
use App\Etoro\RankingQuery;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual, development, fixture-only import of the single canonical offline
 * eToro rankings-page fixture. No live eToro call is ever made — see
 * App\Application\Imports\ImportRankingPageFromFixture and
 * App\Etoro\FixtureSources\RankingFixtureSource. Refuses to run outside
 * local/testing (see handle()'s environment guard) so a synthetic import
 * can never reach a real database.
 */
final class EtoroImportRankingPageCommand extends Command
{
    private const EXPECTED_PAGE = 1;

    private const EXPECTED_PAGE_SIZE = 3;

    /**
     * Documented, non-Symfony exit code: the import completed and produced
     * an ImportRun, but at least one entry was rejected as a controlled
     * trader identity conflict (ImportRunStatus::Partial or ::Failed).
     * Distinct from Command::FAILURE, which is reserved for a fatal fixture
     * read/decode/shape/mapping/pagination/application/persistence
     * Throwable.
     */
    private const EXIT_IMPORT_WITH_REJECTIONS = 3;

    protected $signature = 'etoro:import-ranking-page
        {period : Simulated RankingQuery period metadata for the canonical offline fixture}';

    protected $description = 'Import the canonical offline eToro rankings-page fixture (development, fixture-only — no network request is performed).';

    public function __construct(private readonly ImportRankingPageFromFixture $useCase)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Fail closed BEFORE the fixture is read, the mapper runs, or any
        // DB side effect happens — this command is development-only and
        // must never write synthetic trader rows into a real database.
        // app()->environment() reads the already-resolved runtime
        // environment name from the container, never the .env file or any
        // credential.
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('This command is development-only (local/testing) and cannot run in this environment.');

            return self::FAILURE;
        }

        $period = trim((string) $this->argument('period'));

        if ($period === '') {
            $this->components->error('period must not be blank.');

            return self::INVALID;
        }

        // page/pageSize/sort/country are deliberately not CLI input — this
        // command reads exactly one fixed offline fixture, so they are
        // hard-coded to the values that fixture actually contains, never
        // left for a caller to request a page the fixture cannot provide.
        $rankingQuery = new RankingQuery(
            period: $period,
            page: self::EXPECTED_PAGE,
            pageSize: self::EXPECTED_PAGE_SIZE,
            sort: null,
            country: null,
        );

        $this->components->info('eToro ranking-page import (fixture-only, offline — no network request performed)');

        try {
            $result = $this->useCase->handle($rankingQuery);
        } catch (Throwable) {
            // One catch-all, one fully static message — covers every fatal
            // fixture source/decode/shape/mapping/pagination/application/
            // persistence failure alike. Never the caught exception's own
            // message, path, payload, or identity data: this command has no
            // lower-layer exception dependency to know which of those
            // failure modes actually occurred, by design (Checkpoint C
            // review finding).
            $this->components->error('Offline fixture ranking-page import failed.');

            return self::FAILURE;
        }

        $this->renderSummary($result);

        return $result->importRun->failure_count > 0 ? self::EXIT_IMPORT_WITH_REJECTIONS : self::SUCCESS;
    }

    /**
     * Sanitized, aggregate-only rendering — never a CID, username, payload,
     * fixture path, original exception message, or stack trace.
     */
    private function renderSummary(ImportRankingPageFromFixtureResult $result): void
    {
        $importRun = $result->importRun;

        $this->components->twoColumnDetail('Import run status', $importRun->status->value);
        $this->components->twoColumnDetail(
            'Fixture pagination',
            sprintf('page=%d, pageSize=%d', $result->fixturePagination->page, $result->fixturePagination->pageSize),
        );
        $this->components->twoColumnDetail('Entries mapped', (string) $result->entryCount);
        $this->components->twoColumnDetail('Entries succeeded', (string) $importRun->success_count);
        $this->components->twoColumnDetail('Entries rejected', (string) $importRun->failure_count);

        if ($importRun->failure_count > 0) {
            $this->newLine();
            $this->components->warn('Some entries were rejected due to a controlled trader identity conflict.');
        }
    }
}
