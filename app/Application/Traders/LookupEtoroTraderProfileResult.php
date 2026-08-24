<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Etoro\Data\TraderProfile;
use App\Models\ImportRun;
use App\Models\Trader;

/**
 * Terminal result of LookupEtoroTraderProfile::handle(). Carries the
 * finalized `profile` ImportRun, the stop reason, the mapped TraderProfile
 * (null for any fatal stop before it was mapped), and the locally-matched
 * Trader (null when no exact-username Trader exists — never a newly
 * created one, since this pipeline never creates a Trader from a profile
 * response alone).
 */
final readonly class LookupEtoroTraderProfileResult
{
    public function __construct(
        public ImportRun $importRun,
        public LookupEtoroTraderProfileStopReason $stopReason,
        public ?TraderProfile $profile,
        public ?Trader $matchedTrader,
    ) {}
}
