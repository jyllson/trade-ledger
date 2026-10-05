<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\PerformanceVisibility;
use DateTimeImmutable;

/**
 * Read model for the trader performance UI. A granularity is null when no
 * points of it are stored yet.
 */
final readonly class TraderPerformanceReport
{
    public function __construct(
        public ?TraderPerformanceSeriesReport $monthly,
        public ?TraderPerformanceSeriesReport $daily,
        public ?PerformanceVisibility $visibility,
        public ?DateTimeImmutable $lastSyncedAt,
    ) {}

    public function isEmpty(): bool
    {
        return $this->monthly === null && $this->daily === null;
    }
}
