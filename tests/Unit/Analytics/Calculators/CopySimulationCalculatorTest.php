<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Calculators\CopySimulationCalculator;
use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\Data\PositionSkipReason;
use App\Analytics\Data\SimulatedPosition;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;

function copySimulationCalculator(): CopySimulationCalculator
{
    return new CopySimulationCalculator(new CopyCoverageCalculator);
}

/**
 * @param  list<array{0: string, 1: int}>  $positions  [id, weight ppb]
 */
function copySimulationRequest(int $copyAmountCents, array $positions, int $unmodeled = 0, int $minimumPositionCents = 100): CopyCoverageRequest
{
    return new CopyCoverageRequest(
        copyAmount: Money::fromCents($copyAmountCents),
        minimumPositionAmount: Money::fromCents($minimumPositionCents),
        positions: array_map(fn (array $position): PositionAllocation => new PositionAllocation($position[0], Percentage::fromPartsPerBillion($position[1])), $positions),
        unmodeledEntryCount: $unmodeled,
    );
}

it('returns positions in request order with floor(A × w) estimates', function () {
    // A = $333.33 = 33_333 cents.
    //  s-1  0.3%       → 33_333 × 0.003     = 99.999   → 99 cents, skipped (bp 33_334)
    //  e-1  66.6666667% → 33_333 × 0.666666667 = 22_222.000011… → 22_222, eligible
    //  z-1  0%          → no allocation (null), zero weight
    //  e-2  33.0333333% → 33_333 × 0.330333333 = 11_011.0009… → 11_011, eligible
    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(33_333, [['s-1', 3_000_000], ['e-1', 666_666_667], ['z-1', 0], ['e-2', 330_333_333]]),
        Percentage::zero(),
        Money::fromCents(20_000),
    );

    expect(array_map(fn (SimulatedPosition $position): array => [
        $position->index,
        $position->outcome->positionId,
        $position->outcome->eligible,
        $position->outcome->reason,
        $position->estimatedAmount?->cents(),
    ], $result->positions))->toBe([
        [0, 's-1', false, PositionSkipReason::BelowMinimum, 99],
        [1, 'e-1', true, null, 22_222],
        [2, 'z-1', false, PositionSkipReason::ZeroWeight, null],
        [3, 'e-2', true, null, 11_011],
    ]);
});

it('keeps duplicate ids aligned with their own outcome', function () {
    // d 50% eligible at $200, n −1% negative, d again 0.2% skipped
    // (20_000 × 0.002 = 40 cents < $1).
    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(20_000, [['d', 500_000_000], ['n', -10_000_000], ['d', 2_000_000]]),
        null,
        Money::fromCents(20_000),
    );

    expect(array_map(fn (SimulatedPosition $position): array => [$position->outcome->weight->partsPerBillion(), $position->outcome->reason], $result->positions))
        ->toBe([[500_000_000, null], [-10_000_000, PositionSkipReason::NegativeWeight], [2_000_000, PositionSkipReason::BelowMinimum]])
        ->and($result->warnings)->toBe([
            CopySimulationWarning::DuplicatePositionId,
            CopySimulationWarning::NegativeWeightIgnored,
            CopySimulationWarning::CashWeightUnknown,
        ])
        ->and($result->isEstimate)->toBeTrue()
        ->and($result->unaccountedWeight)->toBeNull();
});

it('treats unaccounted weight up to the tolerance as rounding', function (int $positionsPpb, int $cashPpb, int $unaccounted, bool $warned) {
    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(20_000, [['p', $positionsPpb]]),
        Percentage::fromPartsPerBillion($cashPpb),
        Money::fromCents(20_000),
    );

    expect($result->unaccountedWeight?->partsPerBillion())->toBe($unaccounted)
        ->and(in_array(CopySimulationWarning::UnaccountedWeight, $result->warnings, true))->toBe($warned)
        ->and($result->isEstimate)->toBe($warned);
})->with([
    'exact' => [900_000_000, 100_000_000, 0, false],
    'live-like 99.999986' => [899_999_860, 100_000_000, 140, false],
    'at tolerance' => [899_900_000, 100_000_000, 100_000, false],
    'above tolerance' => [899_899_999, 100_000_000, 100_001, true],
    'over-accounted' => [950_000_000, 100_000_000, -50_000_000, true],
]);

it('computes coverage relative to the positive weight, floored', function () {
    // eligible 2/3 of positive: 200M / 300M = 0.666… → 666_666_666.
    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(20_000, [['a', 200_000_000], ['b', 100_000_000]], minimumPositionCents: 30_000),
        Percentage::fromPartsPerBillion(700_000_000),
        Money::fromCents(20_000),
    );

    // M = $300: a needs ceil(30_000 / 0.2) = 150_000; b 300_000 ⇒ neither
    // at $200. Re-run at $1,500: only a.
    expect($result->coverage->eligibleCount)->toBe(0)
        ->and($result->coverageOfPositiveWeight?->partsPerBillion())->toBe(0);

    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(150_000, [['a', 200_000_000], ['b', 100_000_000]], minimumPositionCents: 30_000),
        Percentage::fromPartsPerBillion(700_000_000),
        Money::fromCents(20_000),
    );

    expect($result->coverageOfPositiveWeight?->partsPerBillion())->toBe(666_666_666)
        ->and($result->warnings)->toBe([]);
});

it('marks unmodeled entries as an estimate and an empty request as not one', function () {
    $unmodeled = copySimulationCalculator()->simulate(copySimulationRequest(20_000, [['a', 900_000_000]], unmodeled: 1), Percentage::fromPartsPerBillion(100_000_000), Money::fromCents(20_000));
    $empty = copySimulationCalculator()->simulate(copySimulationRequest(10_000, []), Percentage::whole(), Money::fromCents(20_000));

    expect($unmodeled->warnings)->toBe([CopySimulationWarning::UnmodeledEntriesPresent])
        ->and($unmodeled->isEstimate)->toBeTrue()
        ->and($empty->warnings)->toBe([CopySimulationWarning::EmptySnapshot, CopySimulationWarning::CopyAmountBelowPlatformMinimum])
        ->and($empty->isEstimate)->toBeFalse()
        ->and($empty->isBelowPlatformMinimum())->toBeTrue()
        ->and($empty->positions)->toBe([])
        ->and($empty->coverageOfPositiveWeight)->toBeNull();
});

it('computes a requested target with the coverage calculator', function () {
    // 100% of {60%, 40%}: §12.3 max($200, ceil($1 / 0.4)) = $200;
    // mathematical = ceil(100 × 10⁹ / 400M) = 250 cents.
    $result = copySimulationCalculator()->simulate(
        copySimulationRequest(20_000, [['a', 600_000_000], ['b', 400_000_000]]),
        Percentage::zero(),
        Money::fromCents(20_000),
        Percentage::whole(),
    );

    expect($result->target?->mathematicalMinimumCopyAmount?->cents())->toBe(250)
        ->and($result->target?->effectiveMinimumCopyAmount?->cents())->toBe(20_000)
        ->and($result->target?->achievedRatio?->partsPerBillion())->toBe(1_000_000_000);
});
