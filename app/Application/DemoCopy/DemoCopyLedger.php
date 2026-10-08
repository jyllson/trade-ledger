<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroDemoCopyClient;
use App\Etoro\EtoroDemoCopyResponse;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Illuminate\Support\Carbon;

/**
 * Reads and writes the `demo_copy_operations` audit log (D-050). The only
 * place that derives copy state from it: which copy is active (mirrorID),
 * and whether a registration is still in flight or unresolved.
 */
final class DemoCopyLedger
{
    /**
     * A `requested` row older than this was never answered (process
     * killed mid-request) and is treated as unresolved, not in flight.
     */
    public const int STALE_REQUEST_MINUTES = 10;

    public function __construct(private readonly DemoCopyResponseSanitizer $sanitizer) {}

    /**
     * @throws DemoCopyRefused when the trader's stored CID cannot be a
     *                         documented int32 parentCID
     */
    public function parentCidOf(Trader $trader): int
    {
        $cid = $trader->external_cid;

        if (preg_match('/\A[1-9]\d{0,9}\z/', $cid) !== 1 || (int) $cid > EtoroDemoCopyClient::MAX_PARENT_CID) {
            throw DemoCopyRefused::because('The trader has no valid eToro CID to copy.');
        }

        return (int) $cid;
    }

    /**
     * The latest successful registration (and its mirrorID). A close never
     * clears it: eToro only acknowledges a close and its completion is not
     * pollable, so the copy is never considered closed automatically —
     * only a newer successful registration supersedes it. While a close is
     * unconfirmed, see unconfirmedClose().
     */
    public function activeCopy(Trader $trader): ?DemoCopyOperation
    {
        return DemoCopyOperation::query()
            ->where('trader_id', $trader->id)
            ->whereIn('type', [DemoCopyOperationType::Start, DemoCopyOperationType::Adjust])
            ->where('status', DemoCopyOperationStatus::Succeeded)
            ->whereNotNull('mirror_id')
            ->latest('id')
            ->first();
    }

    /**
     * The latest close of the active copy's mirror that may have been
     * processed (acknowledged, unknown or unanswered) — its completion is
     * never confirmed through the API. Null when no close was requested
     * since the active copy's registration.
     */
    public function unconfirmedClose(Trader $trader): ?DemoCopyOperation
    {
        $activeCopy = $this->activeCopy($trader);

        if ($activeCopy === null) {
            return null;
        }

        return DemoCopyOperation::query()
            ->where('trader_id', $trader->id)
            ->where('type', DemoCopyOperationType::Close)
            ->where('mirror_id', $activeCopy->mirror_id)
            ->whereIn('status', [DemoCopyOperationStatus::Accepted, DemoCopyOperationStatus::Unknown, DemoCopyOperationStatus::Requested])
            ->where('id', '>', $activeCopy->id)
            ->latest('id')
            ->first();
    }

    /**
     * A start/adjust/close that was sent recently and has no outcome yet.
     */
    public function hasInFlightOperation(Trader $trader): bool
    {
        return DemoCopyOperation::query()
            ->where('trader_id', $trader->id)
            ->where(function ($query): void {
                $query
                    ->where(function ($accepted): void {
                        $accepted->whereIn('type', [DemoCopyOperationType::Start, DemoCopyOperationType::Adjust])
                            ->where('status', DemoCopyOperationStatus::Accepted);
                    })
                    ->orWhere(function ($requested): void {
                        $requested->whereIn('type', [DemoCopyOperationType::Start, DemoCopyOperationType::Adjust, DemoCopyOperationType::Close])
                            ->where('status', DemoCopyOperationStatus::Requested)
                            ->where('created_at', '>=', Carbon::now()->subMinutes(self::STALE_REQUEST_MINUTES));
                    });
            })
            ->exists();
    }

    /**
     * Registrations whose outcome is unknown: they may or may not have
     * moved funds, so a new registration needs an explicit acknowledgment.
     */
    public function unresolvedRegistrationCount(Trader $trader): int
    {
        return DemoCopyOperation::query()
            ->where('trader_id', $trader->id)
            ->whereIn('type', [DemoCopyOperationType::Start, DemoCopyOperationType::Adjust])
            ->where(function ($query): void {
                $query->where('status', DemoCopyOperationStatus::Unknown)
                    ->orWhere(function ($stale): void {
                        $stale->where('status', DemoCopyOperationStatus::Requested)
                            ->where('created_at', '<', Carbon::now()->subMinutes(self::STALE_REQUEST_MINUTES));
                    });
            })
            ->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function begin(DemoCopyOperationType $type, ?Trader $trader, ?int $userId, array $attributes = []): DemoCopyOperation
    {
        return DemoCopyOperation::query()->create([
            'trader_id' => $trader?->id,
            'trader_username' => $trader?->username,
            'user_id' => $userId,
            'type' => $type,
            'status' => DemoCopyOperationStatus::Requested,
            'requested_at' => Carbon::now(),
            ...$attributes,
        ]);
    }

    /**
     * Stores what the transport observed (status, outcome, sanitized body)
     * together with the classified status.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(
        DemoCopyOperation $operation,
        DemoCopyOperationStatus $status,
        ?EtoroDemoCopyResponse $response = null,
        ?string $reason = null,
        array $attributes = [],
    ): DemoCopyOperation {
        $operation->fill([
            'status' => $status,
            'reason' => $reason !== null ? $this->sanitizer->reason($reason) : null,
            'completed_at' => Carbon::now(),
            ...$attributes,
        ]);

        if ($response !== null) {
            $operation->fill([
                'http_status' => $response->httpStatus,
                'transport_outcome' => $response->outcome->value,
                'response' => $response->payload !== [] ? $this->sanitizer->payload($operation->type, $response->payload) : null,
            ]);
        }

        $operation->save();

        return $operation;
    }

    /**
     * Human-readable reason for a non-2xx / unsent / failed transport.
     */
    public function transportReason(EtoroDemoCopyResponse $response): string
    {
        $apiMessage = $this->sanitizer->errorMessage($response->payload);

        return match (true) {
            $response->transportReason === 'local_budget_exhausted' => 'Not sent: the local eToro request budget is exhausted. Try again in '.($response->retryAfterSeconds ?? 60).' s.',
            $response->transportReason !== null => "No response from eToro ({$response->transportReason}); the request may or may not have been processed.",
            $response->httpStatus === 429 => 'eToro rate limit exceeded (HTTP 429); not processed.',
            $apiMessage !== null => "eToro HTTP {$response->httpStatus}: {$apiMessage}",
            default => "eToro HTTP {$response->httpStatus}.",
        };
    }
}
