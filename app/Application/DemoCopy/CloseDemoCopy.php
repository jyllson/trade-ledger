<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\DemoCopyUnregisterType;
use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\EtoroDemoCopyTransportOutcome;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Closes (liquidates) or detaches the active demo copy of a trader by its
 * mirrorID (D-050). eToro documents no pre-check for close; the explicit
 * user confirmation is mandatory. The documented HTTP 200 token is an
 * acknowledgment only and completion is not pollable, so `accepted` means
 * "close requested, NOT confirmed": the ledger keeps the copy active and
 * never marks it closed automatically (DemoCopyLedger::unconfirmedClose()).
 * Any other 2xx body is recorded as unknown.
 *
 * Never retried automatically. A close that may have been processed
 * (accepted or unknown) is sent again only by the user, reusing its
 * clientRequestID — the documented idempotency key — so eToro treats it as
 * the same operation.
 */
final class CloseDemoCopy
{
    private const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly DemoCopyAvailability $availability,
        private readonly DemoCopyLedger $ledger,
        private readonly EtoroDemoCopyClient $client,
    ) {}

    /**
     * @throws DemoCopyRefused before anything is sent
     */
    public function handle(Trader $trader, DemoCopyUnregisterType $unregisterType, ?int $userId, bool $confirmed): DemoCopyOperation
    {
        $this->availability->ensureEnabled();

        if (! $confirmed) {
            throw DemoCopyRefused::because('Explicit confirmation is required before anything is sent to eToro.');
        }

        $parentCid = $this->ledger->parentCidOf($trader);
        $lock = Cache::lock('demo-copy:trader:'.$trader->id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw DemoCopyRefused::because('Another demo copy operation for this trader is being submitted.');
        }

        try {
            return $this->closeLocked($trader, $parentCid, $unregisterType, $userId);
        } finally {
            $lock->release();
        }
    }

    private function closeLocked(Trader $trader, int $parentCid, DemoCopyUnregisterType $unregisterType, ?int $userId): DemoCopyOperation
    {
        if ($this->ledger->hasInFlightOperation($trader)) {
            throw DemoCopyRefused::because('A demo copy operation for this trader is still awaiting its outcome.');
        }

        $activeCopy = $this->ledger->activeCopy($trader);

        if ($activeCopy === null || $activeCopy->mirror_id === null) {
            throw DemoCopyRefused::because('No active demo copy of this trader is known to this application.');
        }

        $mirrorId = $activeCopy->mirror_id;

        $operation = $this->ledger->begin(DemoCopyOperationType::Close, $trader, $userId, [
            'parent_operation_id' => $activeCopy->id,
            'parent_cid' => $parentCid,
            'mirror_id' => $mirrorId,
            'unregister_type' => $unregisterType->value,
            'client_request_id' => $this->unresolvedCloseRequestId($trader, $mirrorId) ?? (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->client->close($mirrorId, $unregisterType, (string) $operation->client_request_id, (string) $operation->request_id);
        } catch (EtoroDemoCopyNotAllowedException|EtoroConfigurationException $exception) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Rejected, reason: 'Not sent: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, reason: 'Unexpected application error while sending; the close may or may not have been processed.');

            throw $exception;
        }

        if (DemoCopyResponseContract::isValidToken($response, (string) $operation->client_request_id)) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Accepted, $response, 'Close requested — eToro acknowledged it, but completion is NOT confirmed and not pollable through the API. Verify in eToro; the copy stays recorded as active.');
        }

        if ($response->successful()) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, $response, 'Unexpected eToro acknowledgment (not the documented HTTP 200 token for this clientRequestID); the close may or may not have been processed. Closing again reuses the same clientRequestID (idempotent).');
        }

        if ($response->outcome === EtoroDemoCopyTransportOutcome::NotSent || $response->refusedByEtoro()) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Rejected, $response, $this->ledger->transportReason($response));
        }

        return $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, $response, $this->ledger->transportReason($response).' Closing again reuses the same clientRequestID (idempotent).');
    }

    /**
     * The clientRequestID of the unconfirmed close of this mirror (if
     * any), so a repeated close is the same eToro operation.
     */
    private function unresolvedCloseRequestId(Trader $trader, int $mirrorId): ?string
    {
        $unconfirmedClose = $this->ledger->unconfirmedClose($trader);

        return $unconfirmedClose?->mirror_id === $mirrorId ? $unconfirmedClose->client_request_id : null;
    }
}
