<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Result of ConsistencyCalculator over COMPLETE periods only (D-032).
 *
 * - A zero return is "flat": counted separately and breaks both streaks.
 * - volatility is the sample standard deviation of per-period returns
 *   (n − 1), NOT annualized; annualizedVolatility (× √12) exists only for
 *   monthly series.
 * - returnExcludingBest(Three) recompounds the complete periods without the
 *   best one (three) — compare with completeCumulativeReturn to see how
 *   much the result depends on a few outlier periods (PROJECT.md §14).
 */
final readonly class ConsistencyResult
{
    public function __construct(
        public string $methodologyVersion,
        public ReturnPeriodGranularity $granularity,
        public int $observedCount,
        public int $positiveCount,
        public int $negativeCount,
        public int $flatCount,
        public ?Percentage $positiveRatio,
        public int $longestPositiveStreak,
        public int $longestNegativeStreak,
        public ?Percentage $volatility,
        public ?Percentage $annualizedVolatility,
        public ?Percentage $bestReturn,
        public ?Percentage $worstReturn,
        public ?Percentage $completeCumulativeReturn,
        public ?Percentage $returnExcludingBest,
        public ?Percentage $returnExcludingBestThree,
    ) {}
}
