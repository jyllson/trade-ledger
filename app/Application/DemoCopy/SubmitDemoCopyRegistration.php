<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\EtoroDemoCopyTransportOutcome;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;
use App\Jobs\PollDemoCopyOutcomeJob;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared body of StartDemoCopy and AdjustDemoCopy (D-050): turns one
 * accepted, fresh, unused pre-check into exactly one register request.
 *
 * Never retried: the referenceID is not an idempotency key (a resubmit
 * would be a second operation). An accepted or unknown request is
 * resolved only by polling its referenceID (PollDemoCopyOutcomeJob).
 */
final class SubmitDemoCopyRegistration
{
    public const int PRE_CHECK_VALID_MINUTES = 10;

    private const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly DemoCopyAvailability $availability,
        private readonly DemoCopyLedger $ledger,
        private readonly EtoroDemoCopyClient $client,
    ) {}

    /**
     * @param  bool  $confirmed  the user explicitly confirmed DEMO account,
     *                           trader and amount
     * @param  bool  $acknowledgeUnresolved  the user accepts that an earlier
     *                                       registration with an unknown
     *                                       outcome may also have executed
     * @param  bool  $acknowledgeUnconfirmedClose  start only: the user verified
     *                                             in eToro that the requested
     *                                             close completed, and accepts
     *                                             that otherwise eToro may treat
     *                                             the start as adding funds to
     *                                             the existing copy
     *
     * @throws DemoCopyRefused before anything is sent
     */
    public function handle(
        DemoCopyOperationType $type,
        DemoCopyOperation $preCheck,
        ?int $userId,
        bool $confirmed,
        bool $acknowledgeUnresolved = false,
        bool $acknowledgeUnconfirmedClose = false,
    ): DemoCopyOperation {
        $this->availability->ensureEnabled();

        if (! $confirmed) {
            throw DemoCopyRefused::because('Explicit confirmation is required before anything is sent to eToro.');
        }

        $trader = $this->validPreCheckTrader($preCheck, $userId);
        $amountCents = (int) $preCheck->amount_cents;
        DemoCopyAmount::assertValidFor($type, $amountCents);
        $parentCid = $this->ledger->parentCidOf($trader);

        if ($parentCid !== $preCheck->parent_cid) {
            throw DemoCopyRefused::because('The trader changed since the pre-check. Run the pre-check again.');
        }

        $lock = Cache::lock('demo-copy:trader:'.$trader->id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw DemoCopyRefused::because('Another demo copy operation for this trader is being submitted.');
        }

        try {
            $operation = $this->submitLocked($type, $preCheck, $trader, $parentCid, $amountCents, $userId, $acknowledgeUnresolved, $acknowledgeUnconfirmedClose);
        } finally {
            $lock->release();
        }

        if ($operation->status === DemoCopyOperationStatus::Accepted || $operation->status === DemoCopyOperationStatus::Unknown) {
            PollDemoCopyOutcomeJob::dispatch($operation)->delay(Carbon::now()->addSeconds(PollDemoCopyOutcomeJob::FIRST_POLL_DELAY_SECONDS));
        }

        return $operation;
    }

    private function submitLocked(
        DemoCopyOperationType $type,
        DemoCopyOperation $preCheck,
        Trader $trader,
        int $parentCid,
        int $amountCents,
        ?int $userId,
        bool $acknowledgeUnresolved,
        bool $acknowledgeUnconfirmedClose,
    ): DemoCopyOperation {
        if ($this->isConsumed($preCheck)) {
            throw DemoCopyRefused::because('This pre-check was already used. Run a new pre-check.');
        }

        if ($this->ledger->hasInFlightOperation($trader)) {
            throw DemoCopyRefused::because('A demo copy operation for this trader is still awaiting its outcome.');
        }

        if (! $acknowledgeUnresolved && $this->ledger->unresolvedRegistrationCount($trader) > 0) {
            throw DemoCopyRefused::because('An earlier demo copy operation for this trader has an unknown outcome. Check it first, or explicitly acknowledge it.');
        }

        $activeCopy = $this->ledger->activeCopy($trader);
        $unconfirmedClose = $this->ledger->unconfirmedClose($trader);

        if ($type === DemoCopyOperationType::Start && $activeCopy !== null && $unconfirmedClose === null) {
            throw DemoCopyRefused::because('This trader is already copied on demo. Use "Adjust demo copy" instead.');
        }

        // The close may not have completed: eToro could then treat this
        // start as adding funds to the existing copy (D-050).
        if ($type === DemoCopyOperationType::Start && $unconfirmedClose !== null && ! $acknowledgeUnconfirmedClose) {
            throw DemoCopyRefused::because(DemoCopyRefused::CLOSE_UNCONFIRMED_START);
        }

        if ($type === DemoCopyOperationType::Adjust && $activeCopy === null) {
            throw DemoCopyRefused::because('No active demo copy of this trader is known to this application.');
        }

        if ($type === DemoCopyOperationType::Adjust && $unconfirmedClose !== null) {
            throw DemoCopyRefused::because(DemoCopyRefused::CLOSE_UNCONFIRMED_ADJUST);
        }

        $operation = $this->ledger->begin($type, $trader, $userId, [
            'parent_operation_id' => $preCheck->id,
            'parent_cid' => $parentCid,
            'amount_cents' => $amountCents,
            'mirror_id' => $type === DemoCopyOperationType::Adjust ? $activeCopy->mirror_id : null,
            'reference_id' => 'tl-'.Str::lower((string) Str::ulid()),
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->client->startOrAdjust($parentCid, $amountCents, (string) $operation->reference_id, (string) $operation->request_id);
        } catch (EtoroDemoCopyNotAllowedException|EtoroConfigurationException $exception) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Rejected, reason: 'Not sent: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, reason: 'Unexpected application error while sending; the request may or may not have been processed.');

            throw $exception;
        }

        if (DemoCopyResponseContract::isValidToken($response)) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Accepted, $response, 'Accepted by eToro for asynchronous processing; awaiting outcome.', [
                'completed_at' => null,
            ]);
        }

        if ($response->successful()) {
            // A 2xx that is not the documented acknowledgment: possibly processed.
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, $response, 'Unexpected eToro acknowledgment (not the documented HTTP 200 token); the request may or may not have been processed.', [
                'completed_at' => null,
            ]);
        }

        if ($response->outcome === EtoroDemoCopyTransportOutcome::NotSent || $response->refusedByEtoro()) {
            return $this->ledger->finish($operation, DemoCopyOperationStatus::Rejected, $response, $this->ledger->transportReason($response));
        }

        // 5xx, unexpected status or transport failure: possibly processed.
        return $this->ledger->finish($operation, DemoCopyOperationStatus::Unknown, $response, $this->ledger->transportReason($response), [
            'completed_at' => null,
        ]);
    }

    private function validPreCheckTrader(DemoCopyOperation $preCheck, ?int $userId): Trader
    {
        if ($preCheck->type !== DemoCopyOperationType::PreCheck || $preCheck->status !== DemoCopyOperationStatus::Accepted) {
            throw DemoCopyRefused::because('A successful eToro pre-check is required first.');
        }

        if ($preCheck->user_id !== $userId) {
            throw DemoCopyRefused::because('The pre-check belongs to another user.');
        }

        if ($preCheck->created_at === null || $preCheck->created_at->lt(Carbon::now()->subMinutes(self::PRE_CHECK_VALID_MINUTES))) {
            throw DemoCopyRefused::because('The pre-check expired (valid for '.self::PRE_CHECK_VALID_MINUTES.' minutes). Run it again.');
        }

        $trader = $preCheck->trader;

        if ($trader === null) {
            throw DemoCopyRefused::because('The trader of this pre-check no longer exists.');
        }

        return $trader;
    }

    private function isConsumed(DemoCopyOperation $preCheck): bool
    {
        return DemoCopyOperation::query()
            ->where('parent_operation_id', $preCheck->id)
            ->whereIn('type', [DemoCopyOperationType::Start, DemoCopyOperationType::Adjust])
            ->exists();
    }
}
