<?php

declare(strict_types=1);

use App\Analytics\Calculators\ReturnDistributionCalculator;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;

require_once __DIR__.'/PerformanceFixtures.php';

it('computes type 7 quartiles and the interquartile range of the reference series', function () {
    // Sorted complete returns: −0.20, 0, 0.05, 0.10, 0.25 (n = 5)
    // Q1: h = 4 × 0.25 = 1 → x₁ = 0; Q3: h = 4 × 0.75 = 3 → x₃ = 0.10; IQR = 0.10
    $result = (new ReturnDistributionCalculator)->calculate(referenceSeries());

    expect($result->methodologyVersion)->toBe('return-distribution-v1')
        ->and($result->granularity)->toBe(ReturnPeriodGranularity::Monthly)
        ->and($result->observedCount)->toBe(5)
        ->and($result->firstQuartile->partsPerBillion())->toBe(0)
        ->and($result->thirdQuartile->partsPerBillion())->toBe(100_000_000)
        ->and($result->interquartileRange->partsPerBillion())->toBe(100_000_000);
});

it('interpolates quartiles between closest ranks', function () {
    // Sorted: 0.01, 0.02, 0.03, 0.04, 0.05, 0.06 (n = 6)
    // Q1: h = 5 × 0.25 = 1.25 → 0.02 + 0.25 × (0.03 − 0.02) = 0.0225
    // Q3: h = 5 × 0.75 = 3.75 → 0.04 + 0.75 × (0.05 − 0.04) = 0.0475
    // IQR = 0.025 (input order does not matter)
    $result = (new ReturnDistributionCalculator)->calculate(monthlySeries([60_000_000, 10_000_000, 40_000_000, 20_000_000, 50_000_000, 30_000_000]));

    expect($result->firstQuartile->partsPerBillion())->toBe(22_500_000)
        ->and($result->thirdQuartile->partsPerBillion())->toBe(47_500_000)
        ->and($result->interquartileRange->partsPerBillion())->toBe(25_000_000);
});

it('measures the contribution and share of the best period in the compounded return', function () {
    // All: 1.1 × 0.8 × 1.25 × 1.0 × 1.05 − 1 = 0.155
    // Without +25%: 1.1 × 0.8 × 1.0 × 1.05 − 1 = −0.076
    // Contribution = 0.155 − (−0.076) = 0.231
    // Share = 0.231 / 0.155 = 1.490322580645… → 1_490_322_581 ppb (half-up)
    $result = (new ReturnDistributionCalculator)->calculate(referenceSeries());

    expect($result->bestPeriodContribution->partsPerBillion())->toBe(231_000_000)
        ->and($result->bestPeriodShareOfReturn->partsPerBillion())->toBe(1_490_322_581);
});

it('keeps the share below one when the other periods also gained', function () {
    // +10%, +10%: all = 1.21 − 1 = 0.21; without one +10%: 0.10
    // contribution = 0.11; share = 0.11 / 0.21 = 0.523809523809… → 523_809_524 ppb
    $result = (new ReturnDistributionCalculator)->calculate(monthlySeries([100_000_000, 100_000_000]));

    expect($result->bestPeriodContribution->partsPerBillion())->toBe(110_000_000)
        ->and($result->bestPeriodShareOfReturn->partsPerBillion())->toBe(523_809_524)
        ->and($result->interquartileRange)->toBeNull();
});

it('has no share of a zero or negative compounded return, but still reports the contribution', function () {
    // +10%, −20%: all = 1.1 × 0.8 − 1 = −0.12; without +10%: −0.20; contribution = 0.08
    $negative = (new ReturnDistributionCalculator)->calculate(monthlySeries([100_000_000, -200_000_000]));
    // +25%, −20%: all = 1.25 × 0.8 − 1 = 0 exactly; without +25%: −0.20; contribution 0.20
    $zero = (new ReturnDistributionCalculator)->calculate(monthlySeries([250_000_000, -200_000_000]));

    expect($negative->bestPeriodContribution->partsPerBillion())->toBe(80_000_000)
        ->and($negative->bestPeriodShareOfReturn)->toBeNull()
        ->and($zero->bestPeriodContribution->partsPerBillion())->toBe(200_000_000)
        ->and($zero->bestPeriodShareOfReturn)->toBeNull();
});

it('uses complete periods only', function () {
    // Partial −50% and in-progress −10% are ignored: complete +10%, +20%
    // all = 1.1 × 1.2 − 1 = 0.32; without +20%: 0.10; contribution 0.22; share 0.22 / 0.32 = 0.6875
    $result = (new ReturnDistributionCalculator)->calculate(monthlySeries([-500_000_000, 100_000_000, 200_000_000, -100_000_000], partialStart: true, inProgress: true));

    expect($result->observedCount)->toBe(2)
        ->and($result->bestPeriodContribution->partsPerBillion())->toBe(220_000_000)
        ->and($result->bestPeriodShareOfReturn->partsPerBillion())->toBe(687_500_000);
});

it('returns nulls where a statistic needs more observations', function () {
    $empty = (new ReturnDistributionCalculator)->calculate(new ReturnSeries(ReturnPeriodGranularity::Monthly, []));
    $single = (new ReturnDistributionCalculator)->calculate(monthlySeries([10_000_000]));
    $three = (new ReturnDistributionCalculator)->calculate(monthlySeries([10_000_000, 20_000_000, 30_000_000]));
    $four = (new ReturnDistributionCalculator)->calculate(monthlySeries([10_000_000, 20_000_000, 30_000_000, 40_000_000]));

    expect($empty->observedCount)->toBe(0)
        ->and($empty->interquartileRange)->toBeNull()
        ->and($empty->bestPeriodContribution)->toBeNull()
        ->and($single->bestPeriodContribution)->toBeNull()
        ->and($single->bestPeriodShareOfReturn)->toBeNull()
        ->and($three->interquartileRange)->toBeNull()
        ->and($three->firstQuartile)->toBeNull()
        // n = 4: Q1 h = 0.75 → 0.0175; Q3 h = 2.25 → 0.0325; IQR 0.015
        ->and($four->interquartileRange->partsPerBillion())->toBe(15_000_000);
});
