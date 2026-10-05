<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;

/**
 * One v2 gain time-series point. `date` is the documented `YYYY-MM-DD`
 * period date at UTC midnight; `gain` is the documented decimal fraction
 * (0.06 = 6%) converted exactly to Percentage ppb (D-032).
 */
final readonly class GainHistoryPoint
{
    public function __construct(
        public DateTimeImmutable $date,
        public Percentage $gain,
    ) {}
}
