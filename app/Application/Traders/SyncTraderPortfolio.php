<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Etoro\Data\LivePortfolio;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\Mappers\LivePortfolioMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use App\Models\PerformanceVisibility;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live, read-only portfolio import for one stored trader
 * (docs/DECISIONS.md D-038):
 * EtoroClient::userLivePortfolio -> LivePortfolioMapper -> normalized
 * content + sha256 `source_hash` -> one `portfolio_snapshots` row with its
 * `portfolio_positions` (in payload order), then best-effort instrument
 * metadata enrichment (EnrichInstrumentMetadata).
 *
 * Idempotent: when the trader's latest snapshot has the same hash, no new
 * snapshot is created — only its `last_confirmed_at` moves forward. Creates
 * exactly one `portfolio` ImportRun per call before the HTTP request. Never
 * creates a Trader. Metadata failures never fail the import; they turn the
 * ImportRun `partial`.
 */
final class SyncTraderPortfolio
{
    private const SOURCE = 'etoro';

    public const TYPE = 'portfolio';

    /**
     * Bump when the normalized content below changes, so hashes of the old
     * and new normalization can never collide silently.
     */
    private const HASH_VERSION = 'portfolio-v1';

    private const RATE_SCALE = 10;

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly LivePortfolioMapper $livePortfolioMapper,
        private readonly EnrichInstrumentMetadata $enrichInstrumentMetadata,
    ) {}

    /**
     * @param  string|null  $queueJobUuid  set by SyncTraderPortfolioJob so an
     *                                     interrupted attempt's run can be closed (D-040)
     */
    public function handle(Trader $trader, ?string $queueJobUuid = null): SyncTraderPortfolioResult
    {
        $importRun = $this->createImportRun($trader, $queueJobUuid);
        $requestCount = 0;

        try {
            try {
                $apiResponse = $this->etoroClient->userLivePortfolio($trader->username);
                $requestCount = $apiResponse->attemptCount;
            } catch (EtoroConfigurationException) {
                return $this->finalize($importRun, SyncTraderPortfolioStopReason::ConfigurationError, 0);
            } catch (EtoroRequestException $exception) {
                return $this->handleRequestFailure($importRun, $trader, $exception);
            } catch (EtoroUnexpectedResponseException $exception) {
                return $this->finalize($importRun, SyncTraderPortfolioStopReason::UnexpectedResponse, $exception->attemptCount);
            }

            try {
                $portfolio = $this->livePortfolioMapper->map($apiResponse->payload);
            } catch (EtoroMappingException $exception) {
                return $this->finalize($importRun, SyncTraderPortfolioStopReason::MappingFailed, $requestCount, mappingError: $exception);
            }

            $positions = $this->normalizedPositions($portfolio);
            $sourceHash = $this->sourceHash($portfolio, $positions);

            [$snapshot, $created] = DB::transaction(
                fn (): array => $this->storeSnapshot($trader, $portfolio, $positions, $sourceHash, now()),
            );

            $enrichment = $this->enrichInstrumentMetadata->handle(
                array_values(array_unique(array_column($positions, 'external_instrument_id'))),
            );

            return $this->finalize(
                $importRun,
                SyncTraderPortfolioStopReason::Completed,
                $requestCount + $enrichment->requestCount,
                snapshot: $snapshot,
                snapshotCreated: $created,
                enrichment: $enrichment,
            );
        } catch (Throwable $exception) {
            // Best-effort recovery save, same contract as
            // SyncTraderPerformance (D-034): if this save itself throws,
            // that exception propagates instead.
            $this->finalize($importRun, SyncTraderPortfolioStopReason::UnexpectedFailure, $requestCount);

            throw $exception;
        }
    }

    private function handleRequestFailure(ImportRun $importRun, Trader $trader, EtoroRequestException $exception): SyncTraderPortfolioResult
    {
        $stopReason = match ($exception->category) {
            EtoroErrorCategory::Authorization => SyncTraderPortfolioStopReason::NotVisible,
            EtoroErrorCategory::NotFound => SyncTraderPortfolioStopReason::NotFound,
            EtoroErrorCategory::RateLimited, EtoroErrorCategory::ServerError, EtoroErrorCategory::ConnectionFailed => SyncTraderPortfolioStopReason::TemporarilyUnavailable,
            EtoroErrorCategory::Authentication, EtoroErrorCategory::Validation => SyncTraderPortfolioStopReason::RequestFailed,
        };

        $visibility = match ($stopReason) {
            SyncTraderPortfolioStopReason::NotVisible => PerformanceVisibility::Private,
            SyncTraderPortfolioStopReason::NotFound => PerformanceVisibility::NotFound,
            default => null,
        };

        if ($visibility !== null) {
            // Stored snapshots are kept: they remain what was observed while
            // the portfolio was public.
            return DB::transaction(function () use ($importRun, $trader, $visibility, $stopReason, $exception): SyncTraderPortfolioResult {
                $trader->forceFill(['portfolio_visibility' => $visibility])->save();

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
     * The persisted form of every position, in payload order. Also the
     * content the source hash is computed over, so what is hashed is
     * exactly what is stored.
     *
     * @return list<array{position_index: int, external_position_id: string, external_instrument_id: string, weight_ppb: int, opened_at: string|null, is_buy: bool|null, leverage: int|null, take_profit_rate: string|null, stop_loss_rate: string|null, trailing_stop_loss: bool|null}>
     */
    private function normalizedPositions(LivePortfolio $portfolio): array
    {
        $positions = [];

        foreach ($portfolio->positions as $index => $position) {
            $positions[] = [
                'position_index' => $index,
                'external_position_id' => $position->positionId,
                'external_instrument_id' => $position->instrumentId,
                'weight_ppb' => $position->weight->partsPerBillion(),
                'opened_at' => $position->openedAt?->format('Y-m-d H:i:s'),
                'is_buy' => $position->isBuy,
                'leverage' => $position->leverage,
                'take_profit_rate' => $this->rate($position->takeProfitRate),
                'stop_loss_rate' => $this->rate($position->stopLossRate),
                'trailing_stop_loss' => $position->trailingStopLoss,
            ];
        }

        return $positions;
    }

    /**
     * Rates arrive as JSON numbers (floats after decoding); they are stored
     * and hashed as a fixed-scale decimal string, never as a float.
     */
    private function rate(?float $rate): ?string
    {
        return $rate === null ? null : number_format($rate, self::RATE_SCALE, '.', '');
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     */
    private function sourceHash(LivePortfolio $portfolio, array $positions): string
    {
        $content = [
            'version' => self::HASH_VERSION,
            'cash_weight_ppb' => $portfolio->cashWeight?->partsPerBillion(),
            'social_trades_count' => $portfolio->socialTradesCount,
            'positions' => $positions,
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Runs inside a transaction holding a lock on the trader row, so two
     * concurrent imports of the same trader cannot both insert a snapshot.
     *
     * @param  list<array{position_index: int, external_position_id: string, external_instrument_id: string, weight_ppb: int, opened_at: string|null, is_buy: bool|null, leverage: int|null, take_profit_rate: string|null, stop_loss_rate: string|null, trailing_stop_loss: bool|null}>  $positions
     * @return array{PortfolioSnapshot, bool} the current snapshot and whether it was created now
     */
    private function storeSnapshot(Trader $trader, LivePortfolio $portfolio, array $positions, string $sourceHash, CarbonInterface $syncedAt): array
    {
        Trader::query()->whereKey($trader->id)->lockForUpdate()->first();

        $latest = PortfolioSnapshot::query()
            ->where('trader_id', $trader->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        $created = $latest === null || $latest->source_hash !== $sourceHash;

        if ($created) {
            $snapshot = PortfolioSnapshot::create([
                'trader_id' => $trader->id,
                'captured_at' => $syncedAt,
                'last_confirmed_at' => $syncedAt,
                'cash_weight_ppb' => $portfolio->cashWeight?->partsPerBillion(),
                'invested_weight_ppb' => array_sum(array_column($positions, 'weight_ppb')),
                'position_count' => count($positions),
                'social_trades_count' => $portfolio->socialTradesCount,
                'source_hash' => $sourceHash,
            ]);

            $this->storePositions($snapshot, $positions, $syncedAt);
        } else {
            $snapshot = $latest;
            $snapshot->forceFill(['last_confirmed_at' => $syncedAt])->save();
        }

        $trader->forceFill([
            'portfolio_synced_at' => $syncedAt,
            'portfolio_visibility' => PerformanceVisibility::Available,
        ])->save();

        return [$snapshot, $created];
    }

    /**
     * Every referenced instrument gets an `instruments` row (bare external
     * id until enriched), so positions always link to one.
     *
     * @param  list<array{position_index: int, external_position_id: string, external_instrument_id: string, weight_ppb: int, opened_at: string|null, is_buy: bool|null, leverage: int|null, take_profit_rate: string|null, stop_loss_rate: string|null, trailing_stop_loss: bool|null}>  $positions
     */
    private function storePositions(PortfolioSnapshot $snapshot, array $positions, CarbonInterface $syncedAt): void
    {
        if ($positions === []) {
            return;
        }

        $externalInstrumentIds = array_values(array_unique(array_column($positions, 'external_instrument_id')));

        Instrument::query()->insertOrIgnore(array_map(fn (string $id): array => [
            'external_instrument_id' => $id,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ], $externalInstrumentIds));

        $instrumentIds = Instrument::query()
            ->whereIn('external_instrument_id', $externalInstrumentIds)
            ->pluck('id', 'external_instrument_id')
            ->all();

        $rows = array_map(fn (array $position): array => [
            ...$position,
            'portfolio_snapshot_id' => $snapshot->id,
            'instrument_id' => $instrumentIds[$position['external_instrument_id']] ?? null,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ], $positions);

        foreach (array_chunk($rows, 500) as $chunk) {
            PortfolioPosition::query()->insert($chunk);
        }
    }

    private function createImportRun(Trader $trader, ?string $queueJobUuid): ImportRun
    {
        return ImportRun::create([
            'source' => self::SOURCE,
            'type' => self::TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => [
                'query' => ['trader_id' => $trader->id],
                ...($queueJobUuid !== null ? ['queue_job_uuid' => $queueJobUuid] : []),
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
        SyncTraderPortfolioStopReason $stopReason,
        int $requestCount,
        ?PortfolioSnapshot $snapshot = null,
        bool $snapshotCreated = false,
        ?EnrichInstrumentMetadataResult $enrichment = null,
        ?int $retryAfterSeconds = null,
        ?EtoroMappingException $mappingError = null,
    ): SyncTraderPortfolioResult {
        $completed = $stopReason === SyncTraderPortfolioStopReason::Completed;
        $degraded = $enrichment !== null && $enrichment->status->isDegraded();

        $importRun->forceFill([
            'status' => match (true) {
                ! $completed => ImportRunStatus::Failed,
                $degraded => ImportRunStatus::Partial,
                default => ImportRunStatus::Completed,
            },
            'request_count' => $requestCount,
            'success_count' => $completed ? 1 : 0,
            'failure_count' => $completed ? ($enrichment->failedRequestCount ?? 0) : 1,
            'finished_at' => now(),
            'error_summary' => match (true) {
                ! $completed => $this->errorSummary($stopReason, $mappingError),
                $degraded => 'Portfolio snapshot stored; instrument metadata enrichment was incomplete.',
                default => null,
            },
            'metadata' => array_merge($importRun->metadata ?? [], [
                'stop_reason' => $stopReason->value,
                'snapshot_id' => $snapshot?->id,
                'snapshot_created' => $snapshotCreated,
                'position_count' => $snapshot->position_count ?? 0,
                'enrichment' => $enrichment?->toMetadata(),
                ...($mappingError !== null ? ['mapping_error' => $this->mappingErrorMetadata($mappingError)] : []),
            ]),
        ])->save();

        return new SyncTraderPortfolioResult(
            $importRun->refresh(),
            $stopReason,
            $snapshot,
            $snapshotCreated,
            $enrichment,
            $retryAfterSeconds,
        );
    }

    /**
     * Static and sanitized — category only, never an exception message,
     * URL, payload, credential, or trader identity.
     */
    private function errorSummary(SyncTraderPortfolioStopReason $stopReason, ?EtoroMappingException $mappingError): string
    {
        return match ($stopReason) {
            SyncTraderPortfolioStopReason::MappingFailed => sprintf(
                'Trader portfolio sync failed: mapping_failed (%s at %s).',
                $mappingError->reason->value ?? 'unknown',
                $mappingError->fieldPath ?? 'unknown',
            ),
            SyncTraderPortfolioStopReason::UnexpectedFailure => 'Unexpected persistence failure while syncing trader portfolio.',
            SyncTraderPortfolioStopReason::NotVisible => 'Trader portfolio is not visible (trader opted out of portfolio exposure).',
            default => sprintf('Trader portfolio sync failed: %s.', $stopReason->value),
        };
    }

    /**
     * Structural diagnosis of a rejected payload (D-044) so a failed run can
     * be explained without another live request. EtoroMappingException
     * carries only static field paths, a reason code and type names — never
     * a value, identity or payload fragment.
     *
     * @return array{mapper: string, field_path: string, reason: string, expected_type: string|null, actual_type: string|null}
     */
    private function mappingErrorMetadata(EtoroMappingException $exception): array
    {
        return [
            'mapper' => $exception->mapper,
            'field_path' => $exception->fieldPath,
            'reason' => $exception->reason->value,
            'expected_type' => $exception->expectedType,
            'actual_type' => $exception->actualType,
        ];
    }
}
