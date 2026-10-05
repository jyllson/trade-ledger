<?php

declare(strict_types=1);

use App\Analytics\Calculators\PerformanceCalculator;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;

require_once __DIR__.'/PerformanceFixtures.php';

it('compounds the reference series instead of summing it', function () {
    $summary = (new PerformanceCalculator)->calculate(referenceSeries());

    // Π(1 + r) − 1 = 1.1 × 0.8 × 1.25 × 1.0 × 1.05 − 1 = 0.155 (a plain sum would be 0.20)
    expect($summary->cumulativeReturn->partsPerBillion())->toBe(155_000_000)
        ->and($summary->methodologyVersion)->toBe('performance-v1')
        ->and($summary->granularity)->toBe(ReturnPeriodGranularity::Monthly)
        ->and($summary->periodCount)->toBe(5)
        ->and($summary->completePeriodCount)->toBe(5);
});

it('builds the equity index after each period starting from 1.0', function () {
    $summary = (new PerformanceCalculator)->calculate(referenceSeries());

    expect(array_map(fn ($point) => $point->equityIndex->partsPerBillion(), $summary->equityCurve))
        ->toBe([1_100_000_000, 880_000_000, 1_100_000_000, 1_100_000_000, 1_155_000_000])
        ->and($summary->equityCurve[0]->periodStart->format('Y-m-d'))->toBe('2020-01-01');
});

it('computes the arithmetic mean and the median of complete periods', function () {
    $summary = (new PerformanceCalculator)->calculate(referenceSeries());

    expect($summary->averageReturn->partsPerBillion())->toBe(40_000_000)
        ->and($summary->medianReturn->partsPerBillion())->toBe(50_000_000);
});

it('averages the two middle values for an even-length median', function () {
    $summary = (new PerformanceCalculator)->calculate(monthlySeries([10_000_000, 40_000_000, -20_000_000, 30_000_000]));

    // sorted: −0.02, 0.01, 0.03, 0.04 → (0.01 + 0.03) / 2 = 0.02
    expect($summary->medianReturn->partsPerBillion())->toBe(20_000_000);
});

it('computes trailing 12-month return from the last 12 complete months only', function () {
    $summary = (new PerformanceCalculator)->calculate(monthlySeries([500_000_000, ...array_fill(0, 12, 10_000_000)]));

    // 1.01^12 − 1 = 0.126825030… (the leading +50% month is outside the window)
    expect($summary->trailing12Return->partsPerBillion())->toBe(126_825_030)
        ->and($summary->trailing24Return)->toBeNull();
});

it('returns null trailing returns for non-monthly series', function () {
    $series = monthlySeries(array_fill(0, 30, 10_000_000), granularity: ReturnPeriodGranularity::Yearly);

    $summary = (new PerformanceCalculator)->calculate($series);

    expect($summary->trailing12Return)->toBeNull()
        ->and($summary->trailing24Return)->toBeNull();
});

it('includes partial and in-progress periods in the cumulative return but not in per-period statistics', function () {
    $series = monthlySeries([500_000_000, 100_000_000, 200_000_000, -100_000_000], partialStart: true, inProgress: true);

    $summary = (new PerformanceCalculator)->calculate($series);

    // 1.5 × 1.1 × 1.2 × 0.9 − 1 = 0.782; complete periods are only +10% and +20%
    expect($summary->cumulativeReturn->partsPerBillion())->toBe(782_000_000)
        ->and($summary->completePeriodCount)->toBe(2)
        ->and($summary->averageReturn->partsPerBillion())->toBe(150_000_000)
        ->and($summary->hasPartialStart)->toBeTrue()
        ->and($summary->hasInProgressPeriod)->toBeTrue();
});

it('returns an empty summary for an empty series', function () {
    $summary = (new PerformanceCalculator)->calculate(new ReturnSeries(ReturnPeriodGranularity::Monthly, []));

    expect($summary->periodCount)->toBe(0)
        ->and($summary->cumulativeReturn)->toBeNull()
        ->and($summary->averageReturn)->toBeNull()
        ->and($summary->medianReturn)->toBeNull()
        ->and($summary->firstPeriodStart)->toBeNull()
        ->and($summary->lastPeriodStart)->toBeNull()
        ->and($summary->equityCurve)->toBe([]);
});
