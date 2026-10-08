<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationType;

/**
 * Starts copying a trader on the eToro DEMO account (D-050), from an
 * accepted PreCheckDemoCopy row and an explicit user confirmation. The
 * outcome is polled by PollDemoCopyOutcomeJob.
 */
final class StartDemoCopy
{
    public function __construct(private readonly SubmitDemoCopyRegistration $submit) {}

    /**
     * @throws DemoCopyRefused before anything is sent
     */
    public function handle(
        DemoCopyOperation $preCheck,
        ?int $userId,
        bool $confirmed,
        bool $acknowledgeUnresolved = false,
        bool $acknowledgeUnconfirmedClose = false,
    ): DemoCopyOperation {
        return $this->submit->handle(DemoCopyOperationType::Start, $preCheck, $userId, $confirmed, $acknowledgeUnresolved, $acknowledgeUnconfirmedClose);
    }
}
