<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Result of ReturnDistributionCalculator over COMPLETE periods only (D-047).
 *
 * - firstQuartile / thirdQuartile / interquartileRange: type 7 quartiles of
 *   per-period returns and their spread (the dispersion of returns); null
 *   with fewer than ReturnDistributionCalculator::MINIMUM_DISPERSION_PERIODS
 *   observations. Not to be confused with volatility (sample standard
 *   deviation, ConsistencyResult).
 * - bestPeriodContribution: compounded return minus the compounded return
 *   without the single best period (a difference of fractions, e.g. 0.231 =
 *   23.1 percentage points); null with fewer than two observations.
 * - bestPeriodShareOfReturn: bestPeriodContribution / compounded return;
 *   null when the compounded return is not positive. May exceed 1 (the
 *   rest of the periods lost money).
 */
final readonly class ReturnDistributionResult
{
    public function __construct(
        public string $methodologyVersion,
        public ReturnPeriodGranularity $granularity,
        public int $observedCount,
        public ?Percentage $firstQuartile,
        public ?Percentage $thirdQuartile,
        public ?Percentage $interquartileRange,
        public ?Percentage $bestPeriodContribution,
        public ?Percentage $bestPeriodShareOfReturn,
    ) {}
}
