<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Etoro\Data\GainHistory;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\GainGranularity;
use App\Etoro\Mappers\GainHistoryMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformancePoint;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live, read-only performance sync for one stored trader
 * (docs/DECISIONS.md D-034):
 * EtoroClient::userGainHistory(monthly, 1000) -> GainHistoryMapper ->
 * exact username check -> the trader's stored monthly series is replaced
 * by the response (upsert + delete of periods no longer returned) in one
 * transaction together with the ImportRun finalize.
 *
 * Creates exactly one `performance` ImportRun per call before the HTTP
 * request, so every failure stays visible in import history. Never
 * creates a Trader. Safe to re-run: the same response leaves the same rows.
 */
final class SyncTraderPerformance
{
    private const SOURCE = 'etoro';

    private const TYPE = 'performance';

    /** Documented maximum — one request returns the deepest monthly history. */
    private const MONTHLY_COUNT = 1000;

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly GainHistoryMapper $gainHistoryMapper,
    ) {}

    public function handle(Trader $trader): SyncTraderPerformanceResult
    {
        $importRun = $this->createImportRun($trader);
        $requestCount = 0;

        try {
            try {
                $apiResponse = $this->etoroClient->userGainHistory($trader->username, GainGranularity::Monthly, self::MONTHLY_COUNT);
                $requestCount = $apiResponse->attemptCount;
            } catch (EtoroConfigurationException) {
                return $this->finalize($importRun, SyncTraderPerformanceStopReason::ConfigurationError, 0);
            } catch (EtoroRequestException $exception) {
                return $this->handleRequestFailure($importRun, $trader, $exception);
            } catch (EtoroUnexpectedResponseException $exception) {
                return $this->finalize($importRun, SyncTraderPerformanceStopReason::UnexpectedResponse, $exception->attemptCount);
            }

            try {
                $history = $this->gainHistoryMapper->map($apiResponse->payload, GainGranularity::Monthly);
            } catch (EtoroMappingException) {
                return $this->finalize($importRun, SyncTraderPerformanceStopReason::MappingFailed, $requestCount);
            }

            if ($history->username !== $trader->username) {
                return $this->finalize($importRun, SyncTraderPerformanceStopReason::IdentityMismatch, $requestCount);
            }

            return DB::transaction(function () use ($importRun, $trader, $history, $requestCount): SyncTraderPerformanceResult {
                $syncedAt = now();
                $stored = $this->replaceMonthlySeries($trader, $history, $syncedAt);

                $trader->forceFill([
                    'performance_synced_at' => $syncedAt,
                    'performance_visibility' => PerformanceVisibility::Available,
                ])->save();

                return $this->finalize($importRun, SyncTraderPerformanceStopReason::Completed, $requestCount, $stored);
            });
        } catch (Throwable $exception) {
            // Best-effort recovery save, same contract as
            // LookupEtoroTraderProfile (D-025): if this save itself throws,
            // that exception propagates instead.
            $this->finalize($importRun, SyncTraderPerformanceStopReason::UnexpectedFailure, $requestCount);

            throw $exception;
        }
    }

    private function handleRequestFailure(ImportRun $importRun, Trader $trader, EtoroRequestException $exception): SyncTraderPerformanceResult
    {
        $stopReason = match ($exception->category) {
            EtoroErrorCategory::Authorization => SyncTraderPerformanceStopReason::NotVisible,
            EtoroErrorCategory::NotFound => SyncTraderPerformanceStopReason::NotFound,
            EtoroErrorCategory::RateLimited, EtoroErrorCategory::ServerError, EtoroErrorCategory::ConnectionFailed => SyncTraderPerformanceStopReason::TemporarilyUnavailable,
            EtoroErrorCategory::Authentication, EtoroErrorCategory::Validation => SyncTraderPerformanceStopReason::RequestFailed,
        };

        $visibility = match ($stopReason) {
            SyncTraderPerformanceStopReason::NotVisible => PerformanceVisibility::Private,
            SyncTraderPerformanceStopReason::NotFound => PerformanceVisibility::NotFound,
            default => null,
        };

        if ($visibility !== null) {
            // Stored history is kept: a trader who later opts out still has
            // the series observed while it was public, marked as such.
            return DB::transaction(function () use ($importRun, $trader, $visibility, $stopReason, $exception): SyncTraderPerformanceResult {
                $trader->forceFill(['performance_visibility' => $visibility])->save();

                return $this->finalize($importRun, $stopReason, $exception->attemptCount);
            });
        }

        return $this->finalize(
            $importRun,
            $stopReason,
            $exception->attemptCount,
            retryAfterSeconds: $stopReason->isRetryable() ? $exception->retryAfterSeconds : null,
        );
    }

    /**
     * @return int number of stored points
     */
    private function replaceMonthlySeries(Trader $trader, GainHistory $history, CarbonInterface $syncedAt): int
    {
        $rows = [];

        foreach ($history->points as $point) {
            $rows[] = [
                'trader_id' => $trader->id,
                'granularity' => ReturnPeriodGranularity::Monthly->value,
                'period_start' => $point->date->format('Y-m-d'),
                'gain_ppb' => $point->gain->partsPerBillion(),
                'source' => PerformancePoint::SOURCE_ETORO_V2_GAIN,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        $series = PerformancePoint::query()
            ->where('trader_id', $trader->id)
            ->where('granularity', ReturnPeriodGranularity::Monthly->value)
            ->where('source', PerformancePoint::SOURCE_ETORO_V2_GAIN);

        (clone $series)
            ->whereNotIn('period_start', array_column($rows, 'period_start'))
            ->delete();

        if ($rows !== []) {
            PerformancePoint::query()->upsert(
                $rows,
                ['trader_id', 'granularity', 'period_start', 'source'],
                ['gain_ppb', 'synced_at', 'updated_at'],
            );
        }

        return count($rows);
    }

    private function createImportRun(Trader $trader): ImportRun
    {
        return ImportRun::create([
            'source' => self::SOURCE,
            'type' => self::TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => [
                'query' => [
                    'trader_id' => $trader->id,
                    'granularity' => GainGranularity::Monthly->value,
                    'count' => self::MONTHLY_COUNT,
                ],
            ],
            'request_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    private function finalize(
        ImportRun $importRun,
        SyncTraderPerformanceStopReason $stopReason,
        int $requestCount,
        int $storedPointCount = 0,
        ?int $retryAfterSeconds = null,
    ): SyncTraderPerformanceResult {
        $completed = $stopReason === SyncTraderPerformanceStopReason::Completed;

        $importRun->forceFill([
            'status' => $completed ? ImportRunStatus::Completed : ImportRunStatus::Failed,
            'request_count' => $requestCount,
            'success_count' => $completed ? 1 : 0,
            'failure_count' => $completed ? 0 : 1,
            'finished_at' => now(),
            'error_summary' => $completed ? null : $this->errorSummary($stopReason),
            'metadata' => array_merge($importRun->metadata ?? [], [
                'stop_reason' => $stopReason->value,
                'stored_point_count' => $storedPointCount,
            ]),
        ])->save();

        return new SyncTraderPerformanceResult($importRun->refresh(), $stopReason, $storedPointCount, $retryAfterSeconds);
    }

    /**
     * Static and sanitized — category only, never an exception message,
     * URL, payload, credential, or trader identity.
     */
    private function errorSummary(SyncTraderPerformanceStopReason $stopReason): string
    {
        return match ($stopReason) {
            SyncTraderPerformanceStopReason::UnexpectedFailure => 'Unexpected persistence failure while syncing trader performance.',
            SyncTraderPerformanceStopReason::NotVisible => 'Trader performance is not visible (trader opted out of portfolio exposure).',
            default => sprintf('Trader performance sync failed: %s.', $stopReason->value),
        };
    }
}
