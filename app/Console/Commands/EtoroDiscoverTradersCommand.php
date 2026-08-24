<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\DiscoverEtoroTradersRequest;
use App\Application\Imports\DiscoverEtoroTradersResult;
use App\Models\ImportRunStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Live, read-only, multi-page eToro ranking discovery. No live eToro call
 * is made unless EtoroClient itself is configured and actually invoked by
 * DiscoverEtoroTraders — see App\Application\Imports\DiscoverEtoroTraders.
 */
final class EtoroDiscoverTradersCommand extends Command
{
    /**
     * Documented, non-Symfony exit code: the aggregate discovery run
     * completed but at least one ranking entry across its pages was
     * rejected as a controlled trader identity conflict, or the page limit
     * was reached before natural completion (ImportRunStatus::Partial).
     * Distinct from Command::FAILURE, which is reserved for a fatal
     * configuration/request/unexpected-response/mapping/pagination/
     * unexpected failure (ImportRunStatus::Failed).
     */
    private const EXIT_DISCOVERY_WITH_REJECTIONS = 3;

    protected $signature = 'etoro:discover-traders
        {period : Ranking period to discover (e.g. lastYear)}
        {--max-pages=1 : Maximum number of ranking pages to fetch in this call (1-20)}
        {--start-page=1 : Ranking page number to start from (>=1)}
        {--sort= : Optional ranking sort parameter}
        {--country= : Optional ranking country filter}';

    protected $description = 'Discover eToro traders via a live, read-only, multi-page ranking sync.';

    public function __construct(private readonly DiscoverEtoroTraders $useCase)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $maxPages = $this->parseNonNegativeInteger((string) $this->option('max-pages'), 'max-pages');

        if ($maxPages === null) {
            return self::INVALID;
        }

        $startPage = $this->parseNonNegativeInteger((string) $this->option('start-page'), 'start-page');

        if ($startPage === null) {
            return self::INVALID;
        }

        $sortOption = $this->option('sort');
        $countryOption = $this->option('country');

        try {
            $request = new DiscoverEtoroTradersRequest(
                period: (string) $this->argument('period'),
                startPage: $startPage,
                maxPages: $maxPages,
                sort: $sortOption !== null ? (string) $sortOption : null,
                country: $countryOption !== null ? (string) $countryOption : null,
            );
        } catch (InvalidArgumentException) {
            // One fully static, sanitized message — never the caught
            // exception's own getMessage(), even for a locally-thrown
            // InvalidArgumentException this command authored the call to.
            $this->components->error('Discovery input is invalid: check period, max-pages (1-20), and start-page (>=1).');

            return self::INVALID;
        }

        $this->components->info('eToro live ranking discovery (multi-page, read-only)');

        try {
            $result = $this->useCase->handle($request);
        } catch (Throwable) {
            // One catch-all, one fully static message — this command has no
            // lower-layer exception dependency to know which failure mode
            // actually occurred (typed failures are already normalized into
            // the aggregate ImportRun by DiscoverEtoroTraders itself).
            $this->components->error('Live ranking discovery failed unexpectedly.');

            return self::FAILURE;
        }

        $this->renderSummary($result);

        return match ($result->importRun->status) {
            ImportRunStatus::Completed => self::SUCCESS,
            ImportRunStatus::Partial => self::EXIT_DISCOVERY_WITH_REJECTIONS,
            default => self::FAILURE,
        };
    }

    /**
     * Accepts only `^\d+$` syntax, then confirms the value is representable
     * as a PHP int via exact BCMath bounds comparison before ever casting —
     * no float conversion anywhere.
     */
    private function parseNonNegativeInteger(string $raw, string $label): ?int
    {
        if (preg_match('/^\d+$/', $raw) !== 1 || ! is_numeric($raw)) {
            $this->components->error("{$label} must be a non-negative integer.");

            return null;
        }

        if (bccomp($raw, (string) PHP_INT_MAX, 0) > 0) {
            $this->components->error("{$label} is outside the representable integer range.");

            return null;
        }

        return (int) $raw;
    }

    /**
     * Sanitized, aggregate-only rendering — never a CID, username, payload,
     * original exception message, or stack trace.
     */
    private function renderSummary(DiscoverEtoroTradersResult $result): void
    {
        $importRun = $result->importRun;

        $this->components->twoColumnDetail('Discovery run status', $importRun->status->value);
        $this->components->twoColumnDetail('Stop reason', $result->stopReason->value);
        $this->components->twoColumnDetail('Pages fetched', (string) $result->pagesFetched);
        $this->components->twoColumnDetail('Requests attempted', (string) $importRun->request_count);
        $this->components->twoColumnDetail('Entries succeeded', (string) $importRun->success_count);
        $this->components->twoColumnDetail('Entries rejected', (string) $importRun->failure_count);

        if ($importRun->error_summary !== null) {
            $this->newLine();
            $this->components->warn($importRun->error_summary);
        }
    }
}
