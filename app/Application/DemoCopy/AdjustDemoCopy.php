<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationType;

/**
 * Adds (positive amount) or removes (negative amount) funds of an active
 * demo copy (D-050) — the same documented register endpoint, which
 * realigns the copy's positions. Needs an accepted PreCheckDemoCopy row
 * and an explicit user confirmation.
 */
final class AdjustDemoCopy
{
    public function __construct(private readonly SubmitDemoCopyRegistration $submit) {}

    /**
     * @throws DemoCopyRefused before anything is sent
     */
    public function handle(DemoCopyOperation $preCheck, ?int $userId, bool $confirmed, bool $acknowledgeUnresolved = false): DemoCopyOperation
    {
        return $this->submit->handle(DemoCopyOperationType::Adjust, $preCheck, $userId, $confirmed, $acknowledgeUnresolved);
    }
}
