<?php

declare(strict_types=1);

namespace App\Application\Etoro;

use App\Analytics\Data\CopyCoverageResult;

final readonly class EvaluateTraderCopyCoverageResult
{
    public function __construct(
        public string $traderUsername,
        public string $requestId,
        public CopyCoverageResult $coverage,
    ) {}
}
