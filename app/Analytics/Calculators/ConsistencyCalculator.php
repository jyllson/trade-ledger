<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\ConsistencyResult;
use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\ReturnMath;
use App\Analytics\ValueObjects\Percentage;

/**
 * Positive/negative/flat period counts, streaks, volatility, and outlier
 * dependency (PROJECT.md §13.4–13.5, §14 Consistency). Uses COMPLETE
 * periods only (D-032). Pure — no Laravel, no I/O.
 */
final class ConsistencyCalculator
{
    public const METHODOLOGY_VERSION = 'consistency-v1';

    public function calculate(ReturnSeries $series): ConsistencyResult
    {
        $returns = array_map(static fn (PeriodReturn $period): Percentage => $period->return, $series->completePeriods());
        $count = count($returns);

        $positive = count(array_filter($returns, static fn (Percentage $r): bool => $r->isPositive()));
        $negative = count(array_filter($returns, static fn (Percentage $r): bool => $r->isNegative()));

        $sortedDescending = $returns;
        usort($sortedDescending, static fn (Percentage $a, Percentage $b): int => $b->compareTo($a));

        $volatility = $this->sampleStandardDeviation($returns);

        return new ConsistencyResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            granularity: $series->granularity,
            observedCount: $count,
            positiveCount: $positive,
            negativeCount: $negative,
            flatCount: $count - $positive - $negative,
            positiveRatio: $count === 0 ? null : ReturnMath::toPercentage(bcdiv((string) $positive, (string) $count, ReturnMath::SCALE)),
            longestPositiveStreak: $this->longestStreak($returns, positive: true),
            longestNegativeStreak: $this->longestStreak($returns, positive: false),
            volatility: $volatility === null ? null : ReturnMath::toPercentage($volatility),
            annualizedVolatility: $volatility === null || $series->granularity !== ReturnPeriodGranularity::Monthly
                ? null
                : ReturnMath::toPercentage(bcmul($volatility, ReturnMath::sqrt('12'), ReturnMath::SCALE)),
            bestReturn: $sortedDescending[0] ?? null,
            worstReturn: $count === 0 ? null : $sortedDescending[$count - 1],
            completeCumulativeReturn: $count === 0 ? null : ReturnMath::compoundedReturn($returns),
            returnExcludingBest: $count < 2 ? null : ReturnMath::compoundedReturn(array_slice($sortedDescending, 1)),
            returnExcludingBestThree: $count < 4 ? null : ReturnMath::compoundedReturn(array_slice($sortedDescending, 3)),
        );
    }

    /**
     * @param  list<Percentage>  $returns
     */
    private function longestStreak(array $returns, bool $positive): int
    {
        $longest = 0;
        $current = 0;

        foreach ($returns as $return) {
            $matches = $positive ? $return->isPositive() : $return->isNegative();
            $current = $matches ? $current + 1 : 0;
            $longest = max($longest, $current);
        }

        return $longest;
    }

    /**
     * Sample standard deviation (n − 1) as a decimal-fraction string, or
     * null with fewer than two observations.
     *
     * @param  list<Percentage>  $returns
     * @return numeric-string|null
     */
    private function sampleStandardDeviation(array $returns): ?string
    {
        if (count($returns) < 2) {
            return null;
        }

        $mean = ReturnMath::mean($returns);
        $sumOfSquares = '0';

        foreach ($returns as $return) {
            $deviation = bcsub(ReturnMath::toFraction($return), $mean, ReturnMath::SCALE);
            $sumOfSquares = bcadd($sumOfSquares, bcmul($deviation, $deviation, ReturnMath::SCALE), ReturnMath::SCALE);
        }

        return ReturnMath::sqrt(bcdiv($sumOfSquares, (string) (count($returns) - 1), ReturnMath::SCALE));
    }
}
