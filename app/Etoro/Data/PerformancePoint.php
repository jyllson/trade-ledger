<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single eToro performance-history data point (one monthly or yearly
 * record) from the v1 `/gain` endpoint. `gain` is retained exactly as
 * received. Live data shows v1 `gain` is in PERCENTAGE POINTS (3.3 = 3.3%),
 * not a decimal fraction — the synthetic fixture's small values do not
 * reflect real units. No summation, compounding, Percentage conversion,
 * or other float-exactness-dependent calculation belongs on this DTO, and
 * v1 data is not an analytics source (see docs/DECISIONS.md D-032).
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
