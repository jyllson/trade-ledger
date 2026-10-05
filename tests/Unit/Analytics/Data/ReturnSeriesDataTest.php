<?php

declare(strict_types=1);

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\ValueObjects\Percentage;

function periodAt(string $date, int $ppb = 0, bool $partialStart = false, bool $inProgress = false): PeriodReturn
{
    return new PeriodReturn(new DateTimeImmutable($date, new DateTimeZone('UTC')), Percentage::fromPartsPerBillion($ppb), $partialStart, $inProgress);
}

it('rejects a return of -100% or worse', function (int $ppb) {
    periodAt('2020-01-01', $ppb);
})->throws(InvalidArgumentException::class)->with([-1_000_000_000, -1_500_000_000]);

it('accepts a return just above -100%', function () {
    expect(periodAt('2020-01-01', -999_999_999)->return->partsPerBillion())->toBe(-999_999_999);
});

it('rejects unsorted or duplicate periods', function (array $dates) {
    new ReturnSeries(ReturnPeriodGranularity::Monthly, array_map(fn (string $date) => periodAt($date), $dates));
})->throws(InvalidArgumentException::class)->with([
    [['2020-02-01', '2020-01-01']],
    [['2020-01-01', '2020-01-01']],
]);

it('allows a partial start only first and an in-progress period only last', function () {
    expect(fn () => new ReturnSeries(ReturnPeriodGranularity::Monthly, [periodAt('2020-01-01'), periodAt('2020-02-01', partialStart: true)]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReturnSeries(ReturnPeriodGranularity::Monthly, [periodAt('2020-01-01', inProgress: true), periodAt('2020-02-01')]))
        ->toThrow(InvalidArgumentException::class);

    $single = new ReturnSeries(ReturnPeriodGranularity::Monthly, [periodAt('2020-01-09', partialStart: true, inProgress: true)]);

    expect($single->completePeriods())->toBe([]);
});

it('rejects non-list or non-PeriodReturn input', function (array $periods) {
    new ReturnSeries(ReturnPeriodGranularity::Monthly, $periods);
})->throws(InvalidArgumentException::class)->with([
    [['a' => null]],
    [['not a period']],
]);
