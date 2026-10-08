<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\EtoroDemoCopyTransportOutcome;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mandatory first step of every start/adjust (D-050): asks eToro whether
 * the registration would be allowed (eligibility dry run, changes
 * nothing) and audits the verdict. Only an `accepted` pre-check row can be
 * submitted by StartDemoCopy / AdjustDemoCopy.
 */
final class PreCheckDemoCopy
{
    public function __construct(
        private readonly DemoCopyAvailability $availability,
        private readonly DemoCopyLedger $ledger,
        private readonly EtoroDemoCopyClient $client,
    ) {}

    /**
     * @throws DemoCopyRefused before anything is sent or recorded
     */
    public function handle(Trader $trader, int $amountCents, DemoCopyOperationType $intent, ?int $userId): DemoCopyOperation
    {
        $this->availability->ensureEnabled();
        DemoCopyAmount::assertValidFor($intent, $amountCents);
        $parentCid = $this->ledger->parentCidOf($trader);

        $activeCopy = $this->ledger->activeCopy($trader);
        $unconfirmedClose = $this->ledger->unconfirmedClose($trader);

        // A start after an unconfirmed close may be pre-checked (dry run);
        // submitting it needs the extra acknowledgment (D-050).
        if ($intent === DemoCopyOperationType::Start && $activeCopy !== null && $unconfirmedClose === null) {
            throw DemoCopyRefused::because('This trader is already copied on demo (mirror '.$activeCopy->mirror_id.'). Use "Adjust demo copy" instead.');
        }

        if ($intent === DemoCopyOperationType::Adjust && $activeCopy === null) {
            throw DemoCopyRefused::because('No active demo copy of this trader is known to this application.');
        }

        if ($intent === DemoCopyOperationType::Adjust && $unconfirmedClose !== null) {
            throw DemoCopyRefused::because(DemoCopyRefused::CLOSE_UNCONFIRMED_ADJUST);
        }

        $operation = $this->ledger->begin(DemoCopyOperationType::PreCheck, $trader, $userId, [
            'parent_cid' => $parentCid,
            'amount_cents' => $amountCents,
            'mirror_id' => $intent === DemoCopyOperationType::Adjust ? $activeCopy->mirror_id : null,
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->client->preCheck($parentCid, $amountCents, (string) $operation->request_id);
        } catch (EtoroDemoCopyNotAllowedException|EtoroConfigurationException $exception) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Rejected, reason: 'Not sent: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, reason: 'Unexpected application error during the pre-check.');

            throw $exception;
        }

        if (! $response->successful()) {
            $status = $response->outcome === EtoroDemoCopyTransportOutcome::NotSent || $response->refusedByEtoro()
                ? DemoCopyOperationStatus::Rejected
                : DemoCopyOperationStatus::Unknown;

            return $this->ledger->finish($operation, $status, $response, $this->ledger->transportReason($response));
        }

        if (! DemoCopyResponseContract::isValidEligibility($response, $parentCid)) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, $response, 'Unexpected pre-check response (not the documented HTTP 200 body for this parentCID); the verdict is unknown.');
        }

        if ($response->payload['isSuccess'] !== true) {
            $errorCode = $response->payload['errorCode'] ?? null;
            $errorMessage = $response->payload['errorMessage'] ?? null;

            return $this->ledger->finish(
                $operation,
                DemoCopyOperationStatus::Rejected,
                $response,
                DemoCopyErrorReason::describe('eToro refused the copy', 'errorCode', $errorCode, $errorMessage),
                ['error_code' => is_int($errorCode) ? (string) $errorCode : null],
            );
        }

        return $this->ledger->finish($operation, DemoCopyOperationStatus::Accepted, $response, 'eToro pre-check: allowed (point-in-time verdict, not a reservation).');
    }
}
