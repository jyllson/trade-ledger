<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\EquityPoint;
use App\Analytics\Data\PerformanceSummary;
use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\ReturnMath;
use App\Analytics\ValueObjects\Percentage;

/**
 * Compounded return, equity index, trailing returns, and per-period
 * average/median (PROJECT.md §13.1–13.2, §14). Pure — no Laravel, no I/O.
 */
final class PerformanceCalculator
{
    public const METHODOLOGY_VERSION = 'performance-v1';

    public function calculate(ReturnSeries $series): PerformanceSummary
    {
        $periods = $series->periods;
        $complete = $series->completePeriods();
        $completeReturns = self::returns($complete);

        return new PerformanceSummary(
            methodologyVersion: self::METHODOLOGY_VERSION,
            granularity: $series->granularity,
            periodCount: count($periods),
            completePeriodCount: count($complete),
            firstPeriodStart: $periods[0]->periodStart ?? null,
            lastPeriodStart: $periods === [] ? null : $periods[count($periods) - 1]->periodStart,
            hasPartialStart: $periods !== [] && $periods[0]->isPartialStart,
            hasInProgressPeriod: $periods !== [] && $periods[count($periods) - 1]->isInProgress,
            cumulativeReturn: $periods === [] ? null : ReturnMath::compoundedReturn(self::returns($periods)),
            trailing12Return: $this->trailingReturn($series->granularity, $completeReturns, 12),
            trailing24Return: $this->trailingReturn($series->granularity, $completeReturns, 24),
            averageReturn: $completeReturns === [] ? null : ReturnMath::toPercentage(ReturnMath::mean($completeReturns)),
            medianReturn: $this->median($completeReturns),
            equityCurve: $this->equityCurve($periods),
        );
    }

    /**
     * @param  list<Percentage>  $completeReturns
     */
    private function trailingReturn(ReturnPeriodGranularity $granularity, array $completeReturns, int $months): ?Percentage
    {
        if ($granularity !== ReturnPeriodGranularity::Monthly || count($completeReturns) < $months) {
            return null;
        }

        return ReturnMath::compoundedReturn(array_slice($completeReturns, -$months));
    }

    /**
     * @param  list<Percentage>  $returns
     */
    private function median(array $returns): ?Percentage
    {
        if ($returns === []) {
            return null;
        }

        $sorted = $returns;
        usort($sorted, static fn (Percentage $a, Percentage $b): int => $a->compareTo($b));

        $count = count($sorted);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$middle];
        }

        return ReturnMath::toPercentage(ReturnMath::mean([$sorted[$middle - 1], $sorted[$middle]]));
    }

    /**
     * @param  list<PeriodReturn>  $periods
     * @return list<EquityPoint>
     */
    private function equityCurve(array $periods): array
    {
        $curve = [];
        $factor = '1';

        foreach ($periods as $period) {
            $factor = bcmul($factor, bcadd('1', ReturnMath::toFraction($period->return), ReturnMath::SCALE), ReturnMath::SCALE);
            $curve[] = new EquityPoint($period->periodStart, ReturnMath::toPercentage($factor));
        }

        return $curve;
    }

    /**
     * @param  list<PeriodReturn>  $periods
     * @return list<Percentage>
     */
    private static function returns(array $periods): array
    {
        return array_map(static fn (PeriodReturn $period): Percentage => $period->return, $periods);
    }
}
