<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Traders\EnrichInstrumentMetadata;
use App\Application\Traders\EnrichInstrumentMetadataResult;
use App\Etoro\Data\AccountPnl;
use App\Etoro\Data\AccountPosition;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroEnvironment;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\Mappers\AccountPnlMapper;
use App\Models\AccountMirror as AccountMirrorModel;
use App\Models\AccountPosition as AccountPositionModel;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Instrument;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live, read-only snapshot of the authenticated eToro account
 * (docs/DECISIONS.md D-051), same contract as SyncTraderPortfolio (D-038):
 * EtoroClient::accountPnl -> AccountPnlMapper -> normalized content +
 * sha256 `source_hash` -> one `account_snapshots` row with its mirrors and
 * positions, then best-effort instrument metadata enrichment.
 *
 * Only environments enabled in code (AccountSyncEnvironment: Demo) are
 * synced — anything else throws before an ImportRun or request exists.
 * Idempotent: identical content only moves `last_confirmed_at`. Creates
 * exactly one `account` ImportRun per call before the HTTP request. No raw
 * payload is stored (D-017).
 */
final class SyncEtoroAccount
{
    private const SOURCE = 'etoro';

    public const TYPE = 'account';

    /**
     * Bump when the normalized content below changes, so hashes of the old
     * and new normalization can never collide silently.
     */
    private const HASH_VERSION = 'account-v1';

    private const LOCK_SECONDS = 30;

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly AccountPnlMapper $accountPnlMapper,
        private readonly EnrichInstrumentMetadata $enrichInstrumentMetadata,
    ) {}

    /**
     * @param  string|null  $queueJobUuid  set by SyncEtoroAccountJob so an
     *                                     interrupted attempt's run can be closed (D-040)
     *
     * @throws AccountSyncNotEnabled for an environment not enabled in code (Real)
     */
    public function handle(EtoroEnvironment $environment, ?string $queueJobUuid = null): SyncEtoroAccountResult
    {
        AccountSyncEnvironment::assertEnabled($environment);

        $importRun = $this->createImportRun($environment, $queueJobUuid);
        $requestCount = 0;

        try {
            try {
                $apiResponse = $this->etoroClient->accountPnl($environment);
                $requestCount = $apiResponse->attemptCount;
            } catch (EtoroConfigurationException) {
                return $this->finalize($importRun, SyncEtoroAccountStopReason::ConfigurationError, 0);
            } catch (EtoroRequestException $exception) {
                return $this->handleRequestFailure($importRun, $exception);
            } catch (EtoroUnexpectedResponseException $exception) {
                return $this->finalize($importRun, SyncEtoroAccountStopReason::UnexpectedResponse, $exception->attemptCount);
            }

            try {
                $pnl = $this->accountPnlMapper->map($apiResponse->payload);
            } catch (EtoroMappingException $exception) {
                return $this->finalize($importRun, SyncEtoroAccountStopReason::MappingFailed, $requestCount, mappingError: $exception);
            }

            $mirrors = $this->normalizedMirrors($pnl);
            $positions = $this->normalizedPositions($pnl);
            $sourceHash = $this->sourceHash($environment, $pnl, $mirrors, $positions);

            [$snapshot, $created] = Cache::lock('etoro-account-snapshot:'.$environment->value, self::LOCK_SECONDS)->block(
                self::LOCK_SECONDS,
                fn (): array => DB::transaction(
                    fn (): array => $this->storeSnapshot($environment, $pnl, $mirrors, $positions, $sourceHash, now()),
                ),
            );

            $enrichment = $this->enrichInstrumentMetadata->handle(
                array_values(array_unique(array_column($positions, 'external_instrument_id'))),
            );

            return $this->finalize(
                $importRun,
                SyncEtoroAccountStopReason::Completed,
                $requestCount + $enrichment->requestCount,
                snapshot: $snapshot,
                snapshotCreated: $created,
                enrichment: $enrichment,
                unmodeledFields: $pnl->unmodeledFields,
            );
        } catch (Throwable $exception) {
            // Best-effort recovery save (same contract as D-034/D-038).
            $this->finalize($importRun, SyncEtoroAccountStopReason::UnexpectedFailure, $requestCount);

            throw $exception;
        }
    }

    private function handleRequestFailure(ImportRun $importRun, EtoroRequestException $exception): SyncEtoroAccountResult
    {
        $stopReason = match ($exception->category) {
            EtoroErrorCategory::Authentication, EtoroErrorCategory::Authorization => SyncEtoroAccountStopReason::NotAuthorized,
            EtoroErrorCategory::RateLimited, EtoroErrorCategory::ServerError, EtoroErrorCategory::ConnectionFailed => SyncEtoroAccountStopReason::TemporarilyUnavailable,
            EtoroErrorCategory::NotFound, EtoroErrorCategory::Validation => SyncEtoroAccountStopReason::RequestFailed,
        };

        return $this->finalize(
            $importRun,
            $stopReason,
            $exception->attemptCount,
            retryAfterSeconds: $stopReason->isRetryable() ? $exception->retryAfterSeconds : null,
        );
    }

    /**
     * Persisted (and hashed) form of every mirror, in payload order.
     *
     * @return list<array{mirror_index: int, external_mirror_id: string, parent_cid: string, parent_username: string|null, initial_investment_cents: int|null, deposit_summary_cents: int|null, withdrawal_summary_cents: int|null, available_amount_cents: int|null, closed_positions_net_profit_cents: int|null, invested_cents: int|null, unrealized_pnl_cents: int|null, position_count: int|null, started_at: string|null, is_paused: bool|null, pending_for_closure: bool|null, status_id: int|null}>
     */
    private function normalizedMirrors(AccountPnl $pnl): array
    {
        $mirrors = [];

        foreach ($pnl->mirrors as $index => $mirror) {
            $pnlValues = $mirror->positions === null ? [null] : array_map(fn (AccountPosition $position): ?int => $position->pnlCents, $mirror->positions);

            $mirrors[] = [
                'mirror_index' => $index,
                'external_mirror_id' => $mirror->mirrorId,
                'parent_cid' => $mirror->parentCid,
                'parent_username' => $mirror->parentUsername,
                'initial_investment_cents' => $mirror->initialInvestmentCents,
                'deposit_summary_cents' => $mirror->depositSummaryCents,
                'withdrawal_summary_cents' => $mirror->withdrawalSummaryCents,
                'available_amount_cents' => $mirror->availableAmountCents,
                'closed_positions_net_profit_cents' => $mirror->closedPositionsNetProfitCents,
                'invested_cents' => $mirror->positions === null ? null : array_sum(array_map(fn (AccountPosition $position): int => $position->amountCents, $mirror->positions)),
                'unrealized_pnl_cents' => in_array(null, $pnlValues, true) ? null : array_sum($pnlValues),
                'position_count' => $mirror->positions === null ? null : count($mirror->positions),
                'started_at' => $mirror->startedAt?->format('Y-m-d H:i:s'),
                'is_paused' => $mirror->isPaused,
                'pending_for_closure' => $mirror->pendingForClosure,
                'status_id' => $mirror->statusId,
            ];
        }

        return $mirrors;
    }

    /**
     * Persisted (and hashed) form of every position: top-level positions
     * first, then each mirror's, in payload order. `mirror_index` links a
     * nested position to its mirror row and is not a column.
     *
     * @return list<array{position_index: int, mirror_index: int|null, external_position_id: string, external_mirror_id: string|null, external_parent_position_id: string|null, external_instrument_id: string, is_buy: bool, amount_cents: int, initial_amount_cents: int|null, units: string|null, open_rate: string|null, opened_at: string|null, leverage: int|null, pnl_cents: int|null}>
     */
    private function normalizedPositions(AccountPnl $pnl): array
    {
        $rows = [];
        $sources = array_map(fn (AccountPosition $position): array => [null, $position], $pnl->positions);

        foreach ($pnl->mirrors as $mirrorIndex => $mirror) {
            foreach ($mirror->positions ?? [] as $position) {
                $sources[] = [$mirrorIndex, $position];
            }
        }

        foreach ($sources as $index => [$mirrorIndex, $position]) {
            $rows[] = [
                'position_index' => $index,
                'mirror_index' => $mirrorIndex,
                'external_position_id' => $position->positionId,
                'external_mirror_id' => $position->mirrorId,
                'external_parent_position_id' => $position->parentPositionId,
                'external_instrument_id' => $position->instrumentId,
                'is_buy' => $position->isBuy,
                'amount_cents' => $position->amountCents,
                'initial_amount_cents' => $position->initialAmountCents,
                'units' => $position->units,
                'open_rate' => $position->openRate,
                'opened_at' => $position->openedAt?->format('Y-m-d H:i:s'),
                'leverage' => $position->leverage,
                'pnl_cents' => $position->pnlCents,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $mirrors
     * @param  list<array<string, mixed>>  $positions
     */
    private function sourceHash(EtoroEnvironment $environment, AccountPnl $pnl, array $mirrors, array $positions): string
    {
        $content = [
            'version' => self::HASH_VERSION,
            'environment' => $environment->value,
            'credit_cents' => $pnl->creditCents,
            'unrealized_pnl_cents' => $pnl->unrealizedPnlCents,
            'bonus_credit_cents' => $pnl->bonusCreditCents,
            'account_currency_id' => $pnl->accountCurrencyId,
            'pending_order_count' => $pnl->pendingOrderCount,
            'orders_amount_cents' => $pnl->ordersAmountCents,
            'manual_orders_for_open_amount_cents' => $pnl->manualOrdersForOpenAmountCents,
            'manual_orders_for_open_external_costs_cents' => $pnl->manualOrdersForOpenExternalCostsCents,
            'mirrors' => $mirrors,
            'positions' => $positions,
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Runs inside a transaction while holding the per-environment lock, so
     * two concurrent syncs cannot both insert the same snapshot.
     *
     * @param  list<array<string, mixed>>  $mirrors
     * @param  list<array{position_index: int, mirror_index: int|null, external_position_id: string, external_mirror_id: string|null, external_parent_position_id: string|null, external_instrument_id: string, is_buy: bool, amount_cents: int, initial_amount_cents: int|null, units: string|null, open_rate: string|null, opened_at: string|null, leverage: int|null, pnl_cents: int|null}>  $positions
     * @return array{AccountSnapshot, bool} the current snapshot and whether it was created now
     */
    private function storeSnapshot(EtoroEnvironment $environment, AccountPnl $pnl, array $mirrors, array $positions, string $sourceHash, CarbonInterface $syncedAt): array
    {
        $latest = AccountSnapshot::query()
            ->where('environment', $environment)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null && $latest->source_hash === $sourceHash) {
            $latest->forceFill(['last_confirmed_at' => $syncedAt])->save();

            return [$latest, false];
        }

        $valuation = AccountValuation::of($pnl);

        $snapshot = AccountSnapshot::create([
            'environment' => $environment,
            'captured_at' => $syncedAt,
            'last_confirmed_at' => $syncedAt,
            'credit_cents' => $pnl->creditCents,
            'unrealized_pnl_cents' => $pnl->unrealizedPnlCents,
            'bonus_credit_cents' => $pnl->bonusCreditCents,
            'account_currency_id' => $pnl->accountCurrencyId,
            'invested_cents' => $valuation->investedCents,
            'equity_cents' => $valuation->equityCents,
            'equity_unavailable_reason' => $valuation->equityUnavailableReason,
            'position_count' => count($pnl->positions),
            'mirror_count' => count($pnl->mirrors),
            'mirror_position_count' => $pnl->mirrorPositionCount(),
            'pending_order_count' => $pnl->pendingOrderCount,
            'source_hash' => $sourceHash,
        ]);

        $mirrorIds = [];

        foreach ($mirrors as $mirror) {
            $mirrorIds[$mirror['mirror_index']] = AccountMirrorModel::create([
                ...$mirror,
                'account_snapshot_id' => $snapshot->id,
            ])->id;
        }

        $this->storePositions($snapshot, $positions, $mirrorIds, $syncedAt);

        return [$snapshot, true];
    }

    /**
     * Every referenced instrument gets an `instruments` row (bare external
     * id until enriched), so positions always link to one (as D-038).
     *
     * @param  list<array{position_index: int, mirror_index: int|null, external_position_id: string, external_mirror_id: string|null, external_parent_position_id: string|null, external_instrument_id: string, is_buy: bool, amount_cents: int, initial_amount_cents: int|null, units: string|null, open_rate: string|null, opened_at: string|null, leverage: int|null, pnl_cents: int|null}>  $positions
     * @param  array<int, int>  $mirrorIds  mirror_index => account_mirrors.id
     */
    private function storePositions(AccountSnapshot $snapshot, array $positions, array $mirrorIds, CarbonInterface $syncedAt): void
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

        $rows = array_map(function (array $position) use ($snapshot, $mirrorIds, $instrumentIds, $syncedAt): array {
            $mirrorIndex = $position['mirror_index'];
            unset($position['mirror_index']);

            return [
                ...$position,
                'account_snapshot_id' => $snapshot->id,
                'account_mirror_id' => $mirrorIndex === null ? null : $mirrorIds[$mirrorIndex],
                'instrument_id' => $instrumentIds[$position['external_instrument_id']] ?? null,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }, $positions);

        foreach (array_chunk($rows, 500) as $chunk) {
            AccountPositionModel::query()->insert($chunk);
        }
    }

    private function createImportRun(EtoroEnvironment $environment, ?string $queueJobUuid): ImportRun
    {
        return ImportRun::create([
            'source' => self::SOURCE,
            'type' => self::TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => [
                'query' => ['environment' => $environment->value],
                ...($queueJobUuid !== null ? ['queue_job_uuid' => $queueJobUuid] : []),
            ],
            'request_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $unmodeledFields
     */
    private function finalize(
        ImportRun $importRun,
        SyncEtoroAccountStopReason $stopReason,
        int $requestCount,
        ?AccountSnapshot $snapshot = null,
        bool $snapshotCreated = false,
        ?EnrichInstrumentMetadataResult $enrichment = null,
        ?int $retryAfterSeconds = null,
        ?EtoroMappingException $mappingError = null,
        array $unmodeledFields = [],
    ): SyncEtoroAccountResult {
        $completed = $stopReason === SyncEtoroAccountStopReason::Completed;
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
                $degraded => 'Account snapshot stored; instrument metadata enrichment was incomplete.',
                default => null,
            },
            'metadata' => array_merge($importRun->metadata ?? [], [
                'stop_reason' => $stopReason->value,
                'snapshot_id' => $snapshot?->id,
                'snapshot_created' => $snapshotCreated,
                'position_count' => $snapshot->position_count ?? 0,
                'mirror_count' => $snapshot->mirror_count ?? 0,
                'enrichment' => $enrichment?->toMetadata(),
                ...($unmodeledFields !== [] ? ['unmodeled_fields' => $unmodeledFields] : []),
                ...($mappingError !== null ? ['mapping_error' => $this->mappingErrorMetadata($mappingError)] : []),
            ]),
        ])->save();

        return new SyncEtoroAccountResult(
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
     * URL, payload, credential or account identity.
     */
    private function errorSummary(SyncEtoroAccountStopReason $stopReason, ?EtoroMappingException $mappingError): string
    {
        return match ($stopReason) {
            SyncEtoroAccountStopReason::MappingFailed => sprintf(
                'Account sync failed: mapping_failed (%s at %s).',
                $mappingError->reason->value ?? 'unknown',
                $mappingError->fieldPath ?? 'unknown',
            ),
            SyncEtoroAccountStopReason::UnexpectedFailure => 'Unexpected persistence failure while syncing the account.',
            SyncEtoroAccountStopReason::NotAuthorized => 'Account sync failed: not_authorized (the API key cannot read this account).',
            default => sprintf('Account sync failed: %s.', $stopReason->value),
        };
    }

    /**
     * Structural diagnosis of a rejected payload (D-044): static field
     * paths, a reason code and type names — never a value.
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
