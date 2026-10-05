<?php

declare(strict_types=1);

use App\Analytics\Calculators\ConsistencyCalculator;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;

require_once __DIR__.'/PerformanceFixtures.php';

it('counts positive, negative, and flat periods in the reference series', function () {
    $result = (new ConsistencyCalculator)->calculate(referenceSeries());

    expect($result->observedCount)->toBe(5)
        ->and($result->positiveCount)->toBe(3)
        ->and($result->negativeCount)->toBe(1)
        ->and($result->flatCount)->toBe(1)
        ->and($result->positiveRatio->partsPerBillion())->toBe(600_000_000)
        ->and($result->methodologyVersion)->toBe('consistency-v1');
});

it('computes the sample standard deviation and the monthly annualized volatility', function () {
    $result = (new ConsistencyCalculator)->calculate(referenceSeries());

    // √(0.107 / 4) = 0.163554272…; × √12 = 0.566568619…
    expect($result->volatility->partsPerBillion())->toBe(163_554_272)
        ->and($result->annualizedVolatility->partsPerBillion())->toBe(566_568_619);
});

it('does not annualize volatility for non-monthly series', function () {
    $result = (new ConsistencyCalculator)->calculate(monthlySeries([10_000_000, 30_000_000], granularity: ReturnPeriodGranularity::Daily));

    expect($result->volatility)->not->toBeNull()
        ->and($result->annualizedVolatility)->toBeNull();
});

it('measures dependency on the best periods by recompounding without them', function () {
    $result = (new ConsistencyCalculator)->calculate(referenceSeries());

    // all: 0.155; without +25%: 1.1 × 0.8 × 1.0 × 1.05 − 1 = −0.076; without +25%, +10%, +5%: 0.8 × 1.0 − 1 = −0.2
    expect($result->bestReturn->partsPerBillion())->toBe(250_000_000)
        ->and($result->worstReturn->partsPerBillion())->toBe(-200_000_000)
        ->and($result->completeCumulativeReturn->partsPerBillion())->toBe(155_000_000)
        ->and($result->returnExcludingBest->partsPerBillion())->toBe(-76_000_000)
        ->and($result->returnExcludingBestThree->partsPerBillion())->toBe(-200_000_000);
});

it('finds the longest positive and negative streaks, with flat periods breaking a streak', function () {
    $result = (new ConsistencyCalculator)->calculate(monthlySeries([10, 20, 30, 0, 40, -10, -20, 5, -30, -40, -50]));

    expect($result->longestPositiveStreak)->toBe(3)
        ->and($result->longestNegativeStreak)->toBe(3);
});

it('excludes partial-start and in-progress periods from every statistic', function () {
    $result = (new ConsistencyCalculator)->calculate(monthlySeries([-500_000_000, 100_000_000, 200_000_000, -100_000_000], partialStart: true, inProgress: true));

    expect($result->observedCount)->toBe(2)
        ->and($result->negativeCount)->toBe(0)
        ->and($result->worstReturn->partsPerBillion())->toBe(100_000_000)
        ->and($result->completeCumulativeReturn->partsPerBillion())->toBe(320_000_000);
});

it('returns nulls where a statistic needs more observations', function () {
    $single = (new ConsistencyCalculator)->calculate(monthlySeries([10_000_000]));
    $three = (new ConsistencyCalculator)->calculate(monthlySeries([10_000_000, 20_000_000, 30_000_000]));
    $empty = (new ConsistencyCalculator)->calculate(new ReturnSeries(ReturnPeriodGranularity::Monthly, []));

    expect($single->volatility)->toBeNull()
        ->and($single->returnExcludingBest)->toBeNull()
        ->and($three->returnExcludingBest)->not->toBeNull()
        ->and($three->returnExcludingBestThree)->toBeNull()
        ->and($empty->positiveRatio)->toBeNull()
        ->and($empty->bestReturn)->toBeNull()
        ->and($empty->worstReturn)->toBeNull()
        ->and($empty->completeCumulativeReturn)->toBeNull();
});
