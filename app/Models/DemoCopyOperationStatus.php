<?php

declare(strict_types=1);

namespace App\Models;

/**
 * State of one demo copy-trading attempt (D-050).
 *
 * - Requested: row written, request not answered yet (or the process died).
 * - Accepted: eToro acknowledged with the documented body (pre-check:
 *   allowed; start/adjust: queued for asynchronous processing; close:
 *   requested, NOT confirmed — completion is not pollable, so the copy is
 *   never treated as closed on this basis).
 * - Rejected: refused before execution (pre-check verdict false, 4xx,
 *   local budget, application rule) — nothing changed on eToro.
 * - Succeeded / Failed: terminal outcome reported by eToro's status poll.
 * - Unknown: delivery or outcome could not be determined (transport error,
 *   5xx, unexpected status, poll limit reached). Never treated as success.
 */
enum DemoCopyOperationStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Accepted => 'info',
            self::Requested => 'gray',
            self::Rejected, self::Failed => 'danger',
            self::Unknown => 'warning',
        };
    }

    /**
     * Registration outcomes that can still change through a status poll.
     */
    public function awaitsOutcome(): bool
    {
        return $this === self::Requested || $this === self::Accepted || $this === self::Unknown;
    }
}
