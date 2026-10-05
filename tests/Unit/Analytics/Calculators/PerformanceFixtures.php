<?php

declare(strict_types=1);

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\ValueObjects\Percentage;

/**
 * Builds a monthly ReturnSeries starting 2020-01-01 from decimal-fraction
 * ppb integers (100_000_000 = +10%).
 *
 * @param  list<int>  $returnsPpb
 */
function monthlySeries(array $returnsPpb, bool $partialStart = false, bool $inProgress = false, ReturnPeriodGranularity $granularity = ReturnPeriodGranularity::Monthly): ReturnSeries
{
    $periods = [];
    $last = count($returnsPpb) - 1;

    foreach ($returnsPpb as $index => $ppb) {
        $periods[] = new PeriodReturn(
            periodStart: new DateTimeImmutable(sprintf('2020-01-01 +%d months', $index), new DateTimeZone('UTC')),
            return: Percentage::fromPartsPerBillion($ppb),
            isPartialStart: $partialStart && $index === 0,
            isInProgress: $inProgress && $index === $last,
        );
    }

    return new ReturnSeries($granularity, $periods);
}

/**
 * Hand-calculated reference series: +10%, −20%, +25%, 0%, +5%.
 *
 * Equity: 1.10, 0.88, 1.10, 1.10, 1.155 → cumulative +15.5%.
 * Mean 0.04, median 0.05, sample stdev √(0.107 / 4) = 0.163554272…
 */
function referenceSeries(): ReturnSeries
{
    return monthlySeries([100_000_000, -200_000_000, 250_000_000, 0, 50_000_000]);
}
