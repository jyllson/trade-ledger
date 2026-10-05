<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;

/**
 * Drawdown at the end of a period: equityₜ / peakₜ − 1, so always ≤ 0
 * (PROJECT.md §13.3).
 */
final readonly class DrawdownPoint
{
    public function __construct(
        public DateTimeImmutable $periodStart,
        public Percentage $drawdown,
    ) {}
}
