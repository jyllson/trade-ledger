<?php

declare(strict_types=1);

namespace App\Etoro\Adapters;

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\PeriodClassifier;
use App\Etoro\Data\GainHistory;
use DateTimeImmutable;

/**
 * Translates a mapped GainHistory into an Analytics ReturnSeries and marks
 * partial periods via PeriodClassifier (D-032):
 *
 * - partial start: the FIRST point of a monthly/yearly series whose date is
 *   not the period start (1st of month / 1 January) — activity began
 *   mid-period;
 * - in progress: the LAST point whose period contains `$asOf` (the capture
 *   time) — the period has not finished yet.
 *
 * No sorting, filtering, or recalculation happens here.
 */
final class GainHistoryReturnSeriesAdapter
{
    public function toReturnSeries(GainHistory $history, DateTimeImmutable $asOf): ReturnSeries
    {
        $granularity = ReturnPeriodGranularity::from($history->granularity->value);
        $lastIndex = count($history->points) - 1;
        $periods = [];

        foreach ($history->points as $index => $point) {
            $periods[] = new PeriodReturn(
                periodStart: $point->date,
                return: $point->gain,
                isPartialStart: $index === 0 && ! PeriodClassifier::isPeriodStart($granularity, $point->date),
                isInProgress: $index === $lastIndex && PeriodClassifier::containsInstant($granularity, $point->date, $asOf),
            );
        }

        return new ReturnSeries($granularity, $periods);
    }
}
