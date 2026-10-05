<?php

declare(strict_types=1);

namespace App\Etoro\Adapters;

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Etoro\Data\GainHistory;
use App\Etoro\Data\GainHistoryPoint;
use App\Etoro\GainGranularity;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Translates a mapped GainHistory into an Analytics ReturnSeries and marks
 * partial periods (D-032):
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
        $points = $history->points;
        $lastIndex = count($points) - 1;
        $periods = [];

        foreach ($points as $index => $point) {
            $periods[] = new PeriodReturn(
                periodStart: $point->date,
                return: $point->gain,
                isPartialStart: $index === 0 && ! $this->isPeriodStart($point, $history->granularity),
                isInProgress: $index === $lastIndex && $this->containsInstant($point, $history->granularity, $asOf),
            );
        }

        return new ReturnSeries($this->granularity($history->granularity), $periods);
    }

    private function isPeriodStart(GainHistoryPoint $point, GainGranularity $granularity): bool
    {
        return match ($granularity) {
            GainGranularity::Daily => true,
            GainGranularity::Monthly => $point->date->format('d') === '01',
            GainGranularity::Yearly => $point->date->format('m-d') === '01-01',
        };
    }

    /**
     * Compares calendar keys in UTC so that a partial-start date (e.g. the
     * 9th) still matches its whole month/year.
     */
    private function containsInstant(GainHistoryPoint $point, GainGranularity $granularity, DateTimeImmutable $asOf): bool
    {
        $asOfUtc = $asOf->setTimezone(new DateTimeZone('UTC'));

        $format = match ($granularity) {
            GainGranularity::Daily => 'Y-m-d',
            GainGranularity::Monthly => 'Y-m',
            GainGranularity::Yearly => 'Y',
        };

        return $point->date->format($format) === $asOfUtc->format($format);
    }

    private function granularity(GainGranularity $granularity): ReturnPeriodGranularity
    {
        return match ($granularity) {
            GainGranularity::Daily => ReturnPeriodGranularity::Daily,
            GainGranularity::Monthly => ReturnPeriodGranularity::Monthly,
            GainGranularity::Yearly => ReturnPeriodGranularity::Yearly,
        };
    }
}
