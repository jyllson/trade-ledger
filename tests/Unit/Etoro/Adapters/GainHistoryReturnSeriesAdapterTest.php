<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Adapters\GainHistoryReturnSeriesAdapter;
use App\Etoro\Data\GainHistory;
use App\Etoro\Data\GainHistoryPoint;
use App\Etoro\GainGranularity;

/**
 * @param  list<string>  $dates
 */
function gainHistoryWithDates(GainGranularity $granularity, array $dates): GainHistory
{
    return new GainHistory(
        username: 'trader_001',
        granularity: $granularity,
        totalGain: null,
        points: array_map(
            fn (string $date) => new GainHistoryPoint(new DateTimeImmutable($date, new DateTimeZone('UTC')), Percentage::fromPartsPerBillion(10_000_000)),
            $dates,
        ),
    );
}

it('marks a mid-month first point as partial start and the current month as in progress', function () {
    $history = gainHistoryWithDates(GainGranularity::Monthly, ['2026-07-09', '2026-08-01', '2026-09-01', '2026-10-01']);

    $series = (new GainHistoryReturnSeriesAdapter)->toReturnSeries($history, new DateTimeImmutable('2026-10-05 11:56:31', new DateTimeZone('UTC')));

    expect($series->granularity)->toBe(ReturnPeriodGranularity::Monthly)
        ->and($series->periods[0]->isPartialStart)->toBeTrue()
        ->and($series->periods[3]->isInProgress)->toBeTrue()
        ->and(count($series->completePeriods()))->toBe(2)
        ->and($series->periods[1]->return->partsPerBillion())->toBe(10_000_000);
});

it('treats every period as complete when the series starts on a period start and the capture is after the last period', function () {
    $history = gainHistoryWithDates(GainGranularity::Monthly, ['2026-07-01', '2026-08-01']);

    $series = (new GainHistoryReturnSeriesAdapter)->toReturnSeries($history, new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));

    expect(count($series->completePeriods()))->toBe(2);
});

it('compares the in-progress period in UTC regardless of the capture time zone', function () {
    $history = gainHistoryWithDates(GainGranularity::Monthly, ['2026-09-01']);

    // 2026-10-01 01:30 in Malta is still 2026-09-30 in UTC
    $series = (new GainHistoryReturnSeriesAdapter)->toReturnSeries($history, new DateTimeImmutable('2026-10-01 01:30', new DateTimeZone('Europe/Malta')));

    expect($series->periods[0]->isInProgress)->toBeTrue();
});

it('applies yearly and daily period boundaries', function () {
    $adapter = new GainHistoryReturnSeriesAdapter;
    $asOf = new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC'));

    $yearly = $adapter->toReturnSeries(gainHistoryWithDates(GainGranularity::Yearly, ['2019-03-09', '2020-01-01', '2026-01-01']), $asOf);
    $daily = $adapter->toReturnSeries(gainHistoryWithDates(GainGranularity::Daily, ['2026-10-03', '2026-10-05']), $asOf);

    expect($yearly->granularity)->toBe(ReturnPeriodGranularity::Yearly)
        ->and($yearly->periods[0]->isPartialStart)->toBeTrue()
        ->and($yearly->periods[2]->isInProgress)->toBeTrue()
        ->and($daily->granularity)->toBe(ReturnPeriodGranularity::Daily)
        ->and($daily->periods[0]->isPartialStart)->toBeFalse()
        ->and($daily->periods[1]->isInProgress)->toBeTrue();
});

it('adapts an empty history to an empty series', function () {
    $series = (new GainHistoryReturnSeriesAdapter)->toReturnSeries(gainHistoryWithDates(GainGranularity::Monthly, []), new DateTimeImmutable);

    expect($series->periods)->toBe([]);
});
