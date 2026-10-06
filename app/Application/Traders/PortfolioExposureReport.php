<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Data\ConcentrationResult;
use App\Analytics\Data\LeverageExposureResult;
use DateTimeImmutable;

/**
 * Concentration and leverage of one stored portfolio snapshot (D-041).
 */
final readonly class PortfolioExposureReport
{
    public function __construct(
        public int $portfolioSnapshotId,
        public DateTimeImmutable $capturedAt,
        public ConcentrationResult $concentration,
        public LeverageExposureResult $leverage,
    ) {}
}
