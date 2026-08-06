<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single eToro performance-history data point (one monthly or yearly
 * record). `gain` is retained as the observed API decimal-fraction value
 * exactly as received — no summation, compounding, Percentage conversion,
 * or other float-exactness-dependent calculation belongs on this DTO (see
 * docs/DECISIONS.md).
 */
final readonly class PerformancePoint
{
    public function __construct(
        public DateTimeImmutable $periodStartedAt,
        public float $gain,
    ) {
        if (is_nan($gain) || is_infinite($gain)) {
            throw new InvalidArgumentException('PerformancePoint gain must be a finite number.');
        }
    }
}
