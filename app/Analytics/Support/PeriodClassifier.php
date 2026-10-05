<?php

declare(strict_types=1);

namespace App\Analytics\Support;

use App\Analytics\Data\ReturnPeriodGranularity;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The single place that decides partial-start and in-progress periods
 * (docs/DECISIONS.md D-032/D-033). Dates are compared as UTC calendar keys.
 */
final class PeriodClassifier
{
    /**
     * A daily period always starts on its own date; monthly/yearly periods
     * start on the 1st of the month / 1 January.
     */
    public static function isPeriodStart(ReturnPeriodGranularity $granularity, DateTimeImmutable $date): bool
    {
        $utc = $date->setTimezone(new DateTimeZone('UTC'));

        return match ($granularity) {
            ReturnPeriodGranularity::Daily => true,
            ReturnPeriodGranularity::Monthly => $utc->format('d') === '01',
            ReturnPeriodGranularity::Yearly => $utc->format('m-d') === '01-01',
        };
    }

    /**
     * Whether the period identified by `$date` contains the instant
     * `$asOf`, i.e. the period had not finished when the data was captured.
     */
    public static function containsInstant(ReturnPeriodGranularity $granularity, DateTimeImmutable $date, DateTimeImmutable $asOf): bool
    {
        $format = match ($granularity) {
            ReturnPeriodGranularity::Daily => 'Y-m-d',
            ReturnPeriodGranularity::Monthly => 'Y-m',
            ReturnPeriodGranularity::Yearly => 'Y',
        };

        $utc = new DateTimeZone('UTC');

        return $date->setTimezone($utc)->format($format) === $asOf->setTimezone($utc)->format($format);
    }
}
