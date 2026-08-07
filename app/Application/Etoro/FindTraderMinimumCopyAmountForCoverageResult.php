<?php

declare(strict_types=1);

namespace App\Application\Etoro;

use App\Analytics\Data\CoverageTargetResult;

final readonly class FindTraderMinimumCopyAmountForCoverageResult
{
    public function __construct(
        public string $traderUsername,
        public string $requestId,
        public CoverageTargetResult $coverageTarget,
    ) {}
}
