<?php

declare(strict_types=1);

use App\Analytics\Calculators\DrawdownCalculator;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;

require_once __DIR__.'/PerformanceFixtures.php';

it('finds the maximum drawdown, its peak, trough, and recovery in the reference series', function () {
    $result = (new DrawdownCalculator)->calculate(referenceSeries());

    // peak 1.10 (Jan) → trough 0.88 (Feb): 0.88 / 1.10 − 1 = −0.20; back to 1.10 in Mar
    expect($result->maxDrawdown->partsPerBillion())->toBe(200_000_000)
        ->and($result->peakPeriodStart->format('Y-m'))->toBe('2020-01')
        ->and($result->troughPeriodStart->format('Y-m'))->toBe('2020-02')
        ->and($result->recoveryPeriodStart->format('Y-m'))->toBe('2020-03')
        ->and($result->methodologyVersion)->toBe('drawdown-v1')
        ->and($result->granularity)->toBe(ReturnPeriodGranularity::Monthly);
});

it('reports the drawdown after every period, never positive', function () {
    $result = (new DrawdownCalculator)->calculate(referenceSeries());

    expect(array_map(fn ($point) => $point->drawdown->partsPerBillion(), $result->drawdownSeries))
        ->toBe([0, -200_000_000, 0, 0, 0]);
});

it('measures a drawdown from the starting equity and reports no recovery yet', function () {
    $result = (new DrawdownCalculator)->calculate(monthlySeries([-100_000_000, -100_000_000, 50_000_000]));

    // equity 0.9, 0.81, 0.8505 against starting peak 1.0 → max drawdown 0.19
    expect($result->maxDrawdown->partsPerBillion())->toBe(190_000_000)
        ->and($result->peakPeriodStart)->toBeNull()
        ->and($result->troughPeriodStart->format('Y-m'))->toBe('2020-02')
        ->and($result->recoveryPeriodStart)->toBeNull();
});

it('keeps the deepest drawdown when a later, shallower one occurs', function () {
    $result = (new DrawdownCalculator)->calculate(monthlySeries([-300_000_000, 500_000_000, -100_000_000]));

    // −30% first; 0.7 × 1.5 = 1.05 recovers in Feb; then −10% from 1.05
    expect($result->maxDrawdown->partsPerBillion())->toBe(300_000_000)
        ->and($result->troughPeriodStart->format('Y-m'))->toBe('2020-01')
        ->and($result->recoveryPeriodStart->format('Y-m'))->toBe('2020-02');
});

it('uses partial and in-progress periods because they are real equity movement', function () {
    $result = (new DrawdownCalculator)->calculate(monthlySeries([-250_000_000, 100_000_000], partialStart: true, inProgress: true));

    expect($result->maxDrawdown->partsPerBillion())->toBe(250_000_000);
});

it('returns a zero drawdown for a series that never falls', function () {
    $result = (new DrawdownCalculator)->calculate(monthlySeries([10_000_000, 0, 20_000_000]));

    expect($result->maxDrawdown->partsPerBillion())->toBe(0)
        ->and($result->peakPeriodStart)->toBeNull()
        ->and($result->troughPeriodStart)->toBeNull()
        ->and($result->recoveryPeriodStart)->toBeNull();
});

it('returns a zero drawdown for an empty series', function () {
    $result = (new DrawdownCalculator)->calculate(new ReturnSeries(ReturnPeriodGranularity::Monthly, []));

    expect($result->maxDrawdown->partsPerBillion())->toBe(0)
        ->and($result->drawdownSeries)->toBe([]);
});

it('recognizes an exact return to the peak as a recovery despite 18-digit truncation', function () {
    // 0.625 × 1.220703125² × 1.073741824 = 1.0 exactly; truncated BCMath gives 0.999…9
    $result = (new DrawdownCalculator)->calculate(monthlySeries([-375_000_000, 220_703_125, 220_703_125, 73_741_824]));

    expect($result->maxDrawdown->partsPerBillion())->toBe(375_000_000)
        ->and($result->recoveryPeriodStart?->format('Y-m'))->toBe('2020-04')
        ->and($result->drawdownSeries[3]->drawdown->partsPerBillion())->toBe(0);
});
