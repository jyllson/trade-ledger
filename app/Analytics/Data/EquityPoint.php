<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;

/**
 * Equity index at the END of a period, starting from 1.0 (= Percentage
 * whole) before the first period (PROJECT.md §13.2).
 */
final readonly class EquityPoint
{
    public function __construct(
        public DateTimeImmutable $periodStart,
        public Percentage $equityIndex,
    ) {}
}
