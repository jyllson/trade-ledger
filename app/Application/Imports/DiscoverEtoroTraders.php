<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\EtoroClient;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\Mappers\RankingsMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Live, read-only, multi-page ranking discovery orchestrator:
 * EtoroClient::rankings() -> RankingsMapper -> ImportRankingPage, repeated
 * page by page. Creates exactly one `rankings_discovery` aggregate ImportRun
 * PER CALL, before the first HTTP request, so transport/mapping/pagination/
 * unexpected failures remain visible in import history even when zero pages
 * ever succeed. Not a generic transport/import framework — this class only
 * ever drives this one specific pipeline.
 */
final class DiscoverEtoroTraders
{
    private const SOURCE = 'etoro';

    private const AGGREGATE_TYPE = 'rankings_discovery';

    private const PAGE_PACING_SECONDS = 2;

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly RankingsMapper $rankingsMapper,
        private readonly ImportRankingPage $importRankingPage,
    ) {}

    public function handle(DiscoverEtoroTradersRequest $request): DiscoverEtoroTradersResult
    {
        $aggregateRun = $this->createAggregateRun($request);

        $pagesFetched = 0;
        $requestCount = 0;
        $successCount = 0;
        $failureCount = 0;
        $childImportRunIds = [];

        try {
            $page = $request->startPage;

            while (true) {
                if ($pagesFetched > 0) {
                    Sleep::sleep(self::PAGE_PACING_SECONDS);
                }

                $rankingQuery = $request->rankingQueryForPage($page);

                try {
                    $apiResponse = $this->etoroClient->rankings($rankingQuery);
                    // request_count reflects real physical HTTP attempts,
                    // including EtoroClient's own internal retries — never a
                    // count of logical page calls.
                    $requestCount += $apiResponse->attemptCount;
                } catch (EtoroConfigurationException $exception) {
                    // No physical HTTP attempt was ever made — the failure
                    // happens in EtoroClient::ensureConfigured(), before the
                    // request loop starts.
                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::ConfigurationError, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                } catch (EtoroRequestException $exception) {
                    $requestCount += $exception->attemptCount;

                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::RequestFailed, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request, $exception->category, $exception->retryAfterSeconds);
                } catch (EtoroUnexpectedResponseException $exception) {
                    $requestCount += $exception->attemptCount;

                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::UnexpectedResponse, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                }

                try {
                    $rankingPage = $this->rankingsMapper->map($apiResponse->payload);
                } catch (EtoroMappingException $exception) {
                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::MappingFailed, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                }

                if (
                    $rankingPage->pagination->page !== $page
                    || $rankingPage->pagination->pageSize !== DiscoverEtoroTradersRequest::PAGE_SIZE
                ) {
                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::PaginationMismatch, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                }

                $childRun = $this->importRankingPage->handle($rankingPage, $rankingQuery, $aggregateRun->id);

                $pagesFetched++;
                $successCount += $childRun->success_count;
                $failureCount += $childRun->failure_count;
                $childImportRunIds[] = $childRun->id;

                if (! $rankingPage->pagination->hasNext) {
                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::NaturalCompletion, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                }

                if ($pagesFetched >= $request->maxPages) {
                    return $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::PageLimitReached, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);
                }

                $page++;
            }
        } catch (Throwable $exception) {
            // Best effort only — mirrors ImportRankingPage's own recovery
            // save contract (docs/DECISIONS.md D-025): this finalize() call
            // is a plain, unwrapped save outside any transaction, not
            // guarded by its own try/catch here, so the two possible
            // outcomes are genuinely different, not just theoretically:
            //   - if this recovery save succeeds, execution reaches
            //     `throw $exception;` below and the original exception
            //     propagates to the caller;
            //   - if this recovery save itself throws, THAT exception
            //     propagates instead — `throw $exception;` below is never
            //     reached — and the aggregate run can be left non-terminal
            //     (e.g. stuck at Running). This is not swallowed or
            //     downgraded; it is a different, real failure the caller
            //     still sees, just not the original one.
            $this->finalize($aggregateRun, DiscoverEtoroTradersStopReason::UnexpectedFailure, $pagesFetched, $requestCount, $successCount, $failureCount, $childImportRunIds, $request);

            throw $exception;
        }
    }

    private function createAggregateRun(DiscoverEtoroTradersRequest $request): ImportRun
    {
        return ImportRun::create([
            'retry_of_import_run_id' => $request->retryOfImportRunId,
            'source' => self::SOURCE,
            'type' => self::AGGREGATE_TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => $this->initialMetadata($request),
            'request_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialMetadata(DiscoverEtoroTradersRequest $request): array
    {
        return [
            'query' => [
                'period' => $request->period,
                'start_page' => $request->startPage,
                'page_size' => DiscoverEtoroTradersRequest::PAGE_SIZE,
                'max_pages' => $request->maxPages,
                'sort' => $request->sort,
                'country' => $request->country,
            ],
        ];
    }

    /**
     * @param  list<int>  $childImportRunIds
     */
    private function finalize(
        ImportRun $aggregateRun,
        DiscoverEtoroTradersStopReason $stopReason,
        int $pagesFetched,
        int $requestCount,
        int $successCount,
        int $failureCount,
        array $childImportRunIds,
        DiscoverEtoroTradersRequest $request,
        ?EtoroErrorCategory $requestErrorCategory = null,
        ?int $retryAfterSeconds = null,
    ): DiscoverEtoroTradersResult {
        $status = $this->determineStatus($stopReason, $pagesFetched, $failureCount);

        $aggregateRun->forceFill([
            'status' => $status,
            'request_count' => $requestCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'finished_at' => now(),
            'error_summary' => $this->buildErrorSummary($stopReason, $status, $pagesFetched, $failureCount),
            'metadata' => array_merge(
                $this->initialMetadata($request),
                [
                    'stop_reason' => $stopReason->value,
                    'pages_fetched' => $pagesFetched,
                    'child_import_run_ids' => $childImportRunIds,
                ],
                $this->retryEligibilityMetadata($stopReason, $requestErrorCategory, $retryAfterSeconds),
            ),
        ])->save();

        return new DiscoverEtoroTradersResult(
            importRun: $aggregateRun->refresh(),
            stopReason: $stopReason,
            pagesFetched: $pagesFetched,
            childImportRunIds: $childImportRunIds,
        );
    }

    /**
     * Sanitized retry-eligibility metadata for every terminal aggregate
     * run. Manual retry is eligible ONLY for a request failure whose
     * category is ServerError, ConnectionFailed, or RateLimited — never
     * Validation/Authentication/Authorization/NotFound, and never any
     * non-request stop reason (configuration, unexpected response,
     * mapping, pagination mismatch, page limit, natural completion, or an
     * unexpected persistence failure). Only the sanitized category enum
     * value and a derived retry_not_before timestamp are ever persisted —
     * never a request ID, transport message/detail, URL, payload,
     * credential, header, or raw exception message.
     *
     * @return array<string, mixed>
     */
    private function retryEligibilityMetadata(
        DiscoverEtoroTradersStopReason $stopReason,
        ?EtoroErrorCategory $requestErrorCategory,
        ?int $retryAfterSeconds,
    ): array {
        $metadata = ['retryable' => $this->isRetryable($stopReason, $requestErrorCategory)];

        if ($requestErrorCategory !== null) {
            $metadata['request_error_category'] = $requestErrorCategory->value;

            if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
                $metadata['retry_not_before'] = now()->addSeconds($retryAfterSeconds)->toIso8601String();
            }
        }

        return $metadata;
    }

    private function isRetryable(DiscoverEtoroTradersStopReason $stopReason, ?EtoroErrorCategory $requestErrorCategory): bool
    {
        if ($stopReason !== DiscoverEtoroTradersStopReason::RequestFailed || $requestErrorCategory === null) {
            return false;
        }

        return in_array($requestErrorCategory, [
            EtoroErrorCategory::ServerError,
            EtoroErrorCategory::ConnectionFailed,
            EtoroErrorCategory::RateLimited,
        ], true);
    }

    private function determineStatus(DiscoverEtoroTradersStopReason $stopReason, int $pagesFetched, int $failureCount): ImportRunStatus
    {
        if ($stopReason === DiscoverEtoroTradersStopReason::NaturalCompletion && $pagesFetched > 0) {
            return $failureCount === 0 ? ImportRunStatus::Completed : ImportRunStatus::Partial;
        }

        return $pagesFetched > 0 ? ImportRunStatus::Partial : ImportRunStatus::Failed;
    }

    /**
     * Fully static/sanitized — category and counts only, never the original
     * exception message, URL, payload, or any ranking entry identity.
     */
    private function buildErrorSummary(DiscoverEtoroTradersStopReason $stopReason, ImportRunStatus $status, int $pagesFetched, int $failureCount): ?string
    {
        if ($status === ImportRunStatus::Completed) {
            return null;
        }

        if ($status === ImportRunStatus::Failed) {
            return sprintf('Discovery failed before any ranking page was imported: %s.', $stopReason->value);
        }

        $parts = [];

        if ($stopReason === DiscoverEtoroTradersStopReason::PageLimitReached) {
            $parts[] = 'Stopped after reaching the configured page limit; more pages may exist.';
        } elseif ($stopReason !== DiscoverEtoroTradersStopReason::NaturalCompletion) {
            $parts[] = sprintf(
                'Discovery stopped after %d page%s due to: %s.',
                $pagesFetched,
                $pagesFetched === 1 ? '' : 's',
                $stopReason->value,
            );
        }

        if ($failureCount > 0) {
            $parts[] = sprintf(
                '%d ranking %s rejected across %d page%s due to controlled trader identity conflicts.',
                $failureCount,
                $failureCount === 1 ? 'entry was' : 'entries were',
                $pagesFetched,
                $pagesFetched === 1 ? '' : 's',
            );
        }

        return implode(' ', $parts);
    }
}
