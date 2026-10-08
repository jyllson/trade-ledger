<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use Illuminate\Support\Str;
use Throwable;

/**
 * One status lookup of a start/adjust by its referenceID (D-050), audited
 * as its own `poll` row. Only a documented terminal outcome (the HTTP 200
 * `CopyTradingStatusResponse` for exactly this referenceID and parentCID,
 * checked by DemoCopyResponseContract) moves the registration
 * to succeeded/failed; everything else (404 = not terminal yet, errors)
 * leaves it awaiting. Never marks anything succeeded by assumption.
 */
final class PollDemoCopyOutcome
{
    public function __construct(
        private readonly DemoCopyAvailability $availability,
        private readonly DemoCopyLedger $ledger,
        private readonly EtoroDemoCopyClient $client,
    ) {}

    /**
     * @return DemoCopyOperation|null the poll row; null when the
     *                                registration needs no poll
     *
     * @throws DemoCopyRefused when demo copy is disabled
     */
    public function handle(DemoCopyOperation $registration, ?int $userId = null): ?DemoCopyOperation
    {
        $registration->refresh();

        if (! $registration->type->isRegistration() || $registration->reference_id === null || ! $registration->status->awaitsOutcome()) {
            return null;
        }

        $this->availability->ensureEnabled();

        $poll = $this->ledger->begin(DemoCopyOperationType::Poll, $registration->trader, $userId, [
            'trader_username' => $registration->trader_username,
            'parent_operation_id' => $registration->id,
            'parent_cid' => $registration->parent_cid,
            'reference_id' => $registration->reference_id,
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->client->pollOutcome($registration->reference_id, (string) $poll->request_id);
        } catch (EtoroDemoCopyNotAllowedException|EtoroConfigurationException $exception) {
            return $this->ledger->finish($poll, DemoCopyOperationStatus::Rejected, reason: 'Not sent: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->ledger->finish($poll, DemoCopyOperationStatus::Unknown, reason: 'Unexpected application error during the status poll.');

            throw $exception;
        }

        if ($response->httpStatus === 404) {
            return $this->ledger->finish($poll, DemoCopyOperationStatus::Unknown, $response, 'No terminal outcome yet (still processing, or never received).');
        }

        if (! $response->successful()) {
            return $this->ledger->finish($poll, DemoCopyOperationStatus::Unknown, $response, $this->ledger->transportReason($response));
        }

        if (! DemoCopyResponseContract::isValidStatus($response, $registration->reference_id, $registration->parent_cid)) {
            return $this->ledger->finish($poll, DemoCopyOperationStatus::Unknown, $response, 'Unexpected status response; the outcome is still unknown.');
        }

        $mirrorId = $response->payload['mirrorID'] ?? null;

        if ($response->payload['isSuccess'] === true) {
            $this->ledger->finish($poll, DemoCopyOperationStatus::Succeeded, $response, 'eToro: operation succeeded.', ['mirror_id' => $mirrorId]);
            $this->ledger->finish($registration, DemoCopyOperationStatus::Succeeded, reason: 'eToro: operation succeeded (mirror '.$mirrorId.').', attributes: [
                'mirror_id' => $mirrorId,
            ]);

            return $poll;
        }

        $failReason = $response->payload['failReason'] ?? null;
        $errorCode = $response->payload['errorMessageCode'] ?? null;
        $reason = 'eToro: operation failed'.(is_string($failReason) && trim($failReason) !== '' ? ' — '.$failReason : ' (no reason given)').'.';

        $this->ledger->finish($poll, DemoCopyOperationStatus::Failed, $response, $reason);
        $this->ledger->finish($registration, DemoCopyOperationStatus::Failed, reason: $reason, attributes: [
            'error_code' => is_int($errorCode) ? (string) $errorCode : null,
        ]);

        return $poll;
    }

    /**
     * Called when polling stops (limit reached, job failed, demo copy
     * disabled meanwhile) without a terminal outcome.
     */
    public function giveUp(DemoCopyOperation $registration, string $reason): void
    {
        $registration->refresh();

        if (! $registration->type->isRegistration() || ! $registration->status->awaitsOutcome()) {
            return;
        }

        $this->ledger->finish($registration, DemoCopyOperationStatus::Unknown, reason: $reason.' Verify the copy in eToro, or use "Check outcome" later.', attributes: [
            'completed_at' => null,
        ]);
    }
}
