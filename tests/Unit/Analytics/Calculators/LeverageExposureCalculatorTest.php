<?php

declare(strict_types=1);

use App\Analytics\Calculators\LeverageExposureCalculator;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\ExposureWeightBasis;

require_once __DIR__.'/ExposureFixtures.php';

it('computes weighted leverage, leveraged weight, max leverage and the leveraged count', function () {
    $result = (new LeverageExposureCalculator)->calculate(referenceExposurePortfolio());

    // Invested-only weights (÷ 90%): 30/90, 10/90, 20/90, 15/90, 15/90
    // Σ wᵢ × Lᵢ = (30×1 + 10×2 + 20×1 + 15×5 + 15×1) / 90 = 160/90 = 1.777777777|78
    // leveraged (L > 1): p2 + p4 = 25/90 = 0.2777777777|8; 1x: 65/90 = 0.7222222222
    expect($result->methodologyVersion)->toBe('leverage-v1')
        ->and($result->weightBasis)->toBe(ExposureWeightBasis::InvestedOnly)
        ->and($result->status)->toBe(ExposureStatus::Complete)
        ->and($result->unavailableReason)->toBeNull()
        ->and($result->weightedLeverage)->toBe('1.777777778')
        ->and($result->knownLeverageContribution)->toBe('1.777777778')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->leveragedWeight->partsPerBillion())->toBe(277_777_778)
        ->and($result->unleveragedWeight->partsPerBillion())->toBe(722_222_222)
        ->and($result->unknownLeverageWeight->partsPerBillion())->toBe(0)
        ->and($result->maxLeverage)->toBe(5)
        ->and($result->leveragedPositionCount)->toBe(2)
        ->and($result->knownLeverageCount)->toBe(5)
        ->and($result->missingLeverageCount)->toBe(0)
        ->and($result->invalidLeverageCount)->toBe(0)
        ->and($result->missingWeightCount)->toBe(0)
        ->and($result->negativeWeightCount)->toBe(0);
});

it('never assumes 1x for a missing leverage and reports its weight as unknown', function () {
    $result = (new LeverageExposureCalculator)->calculate(incompleteExposurePortfolio());

    // Usable W = 100% (a 50% 1x, b 30% unknown, c 20% 3x; d has no weight).
    // §13.7 Σ wᵢ × Lᵢ needs L_b ⇒ not determinable, weightedLeverage = null
    // (assuming b = 1x would wrongly give 1.4; renormalizing to the known
    // part, 110/70 = 1.571428571, would silently change the basis)
    // known contribution on the invested basis: 0.5×1 + 0.2×3 = 1.1
    // known weight = a + c = 70%
    // leveraged = c = 20%, 1x = a = 50%, unknown = b = 30%
    // counts use every holding: known a, c, d (d = 2x, no weight) ⇒ leveraged c, d; max 3
    expect($result->status)->toBe(ExposureStatus::Partial)
        ->and($result->weightedLeverage)->toBeNull()
        ->and($result->knownLeverageContribution)->toBe('1.100000000')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(700_000_000)
        ->and($result->leveragedWeight->partsPerBillion())->toBe(200_000_000)
        ->and($result->unleveragedWeight->partsPerBillion())->toBe(500_000_000)
        ->and($result->unknownLeverageWeight->partsPerBillion())->toBe(300_000_000)
        ->and($result->maxLeverage)->toBe(3)
        ->and($result->leveragedPositionCount)->toBe(2)
        ->and($result->knownLeverageCount)->toBe(3)
        ->and($result->missingLeverageCount)->toBe(1);
});

it('reports an invalid leverage below 1 as unknown, not as a value', function () {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 600_000_000, leverage: 2),
        exposureHolding('b', '2', 400_000_000, leverage: 0),
    ]));

    // weighted leverage not determinable; known contribution 0.6×2 = 1.2; unknown = 40%
    expect($result->status)->toBe(ExposureStatus::Partial)
        ->and($result->weightedLeverage)->toBeNull()
        ->and($result->knownLeverageContribution)->toBe('1.200000000')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(600_000_000)
        ->and($result->unknownLeverageWeight->partsPerBillion())->toBe(400_000_000)
        ->and($result->invalidLeverageCount)->toBe(1)
        ->and($result->maxLeverage)->toBe(2);
});

it('is unavailable when no invested weight has a known leverage', function () {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 700_000_000, leverage: null),
        exposureHolding('b', '2', 300_000_000, leverage: null),
    ]));

    expect($result->status)->toBe(ExposureStatus::Unavailable)
        ->and($result->unavailableReason)->toBe(ExposureUnavailableReason::NoData)
        ->and($result->weightedLeverage)->toBeNull()
        ->and($result->knownLeverageContribution)->toBe('0.000000000')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(0)
        ->and($result->unknownLeverageWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->maxLeverage)->toBeNull()
        ->and($result->missingLeverageCount)->toBe(2);
});

it('is unavailable for an empty or cash-only portfolio', function (?int $cashPpb) {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([], cashPpb: $cashPpb));

    expect($result->status)->toBe(ExposureStatus::Unavailable)
        ->and($result->unavailableReason)->toBe(ExposureUnavailableReason::NoInvestedWeight)
        ->and($result->weightedLeverage)->toBeNull()
        ->and($result->knownLeverageContribution)->toBeNull()
        ->and($result->knownLeverageWeight)->toBeNull()
        ->and($result->leveragedWeight)->toBeNull()
        ->and($result->unleveragedWeight)->toBeNull()
        ->and($result->unknownLeverageWeight)->toBeNull()
        ->and($result->maxLeverage)->toBeNull()
        ->and($result->leveragedPositionCount)->toBe(0);
})->with(['empty' => [null], 'cash only' => [1_000_000_000]]);

it('still computes weighted leverage when the unknown leverage has no usable weight', function () {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 600_000_000, leverage: 1),
        exposureHolding('b', '2', 400_000_000, leverage: 4),
        exposureHolding('c', '3', null, leverage: null),
    ]));

    // c carries no weight ⇒ §13.7 is fully determined: 0.6×1 + 0.4×4 = 2.2;
    // status stays Partial because c's leverage and weight are still missing
    expect($result->status)->toBe(ExposureStatus::Partial)
        ->and($result->weightedLeverage)->toBe('2.200000000')
        ->and($result->knownLeverageContribution)->toBe('2.200000000')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->missingLeverageCount)->toBe(1)
        ->and($result->missingWeightCount)->toBe(1);
});

it('returns the position leverage for a single position', function () {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([
        exposureHolding('only', '1', 250_000_000, leverage: 10),
    ], cashPpb: 750_000_000));

    // w = 1 ⇒ Σ wᵢ × Lᵢ = 10; whole invested weight is leveraged
    expect($result->weightedLeverage)->toBe('10.000000000')
        ->and($result->leveragedWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->leveragedPositionCount)->toBe(1);
});

it('is partial and counts positions whose weight is missing or negative', function () {
    $result = (new LeverageExposureCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 600_000_000, leverage: 1),
        exposureHolding('b', '2', null, leverage: 5),
        exposureHolding('c', '3', -50_000_000, leverage: 2),
    ], cashPpb: 400_000_000));

    // Usable W = a = 60% (b has no weight, c a negative one ⇒ both outside
    // the invested basis, as in ConcentrationCalculator).
    // §13.7 over W: 600/600 × 1 = 1 ⇒ weightedLeverage = 1 — a value over
    // the known invested basis, but NOT Complete: b's 5x exposure share is
    // unknown, so the result is Partial and carries the excluded counts.
    // counts use every holding: known a, b, c ⇒ leveraged b, c; max 5
    expect($result->status)->toBe(ExposureStatus::Partial)
        ->and($result->unavailableReason)->toBeNull()
        ->and($result->weightedLeverage)->toBe('1.000000000')
        ->and($result->knownLeverageContribution)->toBe('1.000000000')
        ->and($result->knownLeverageWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->leveragedWeight->partsPerBillion())->toBe(0)
        ->and($result->unleveragedWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->unknownLeverageWeight->partsPerBillion())->toBe(0)
        ->and($result->missingWeightCount)->toBe(1)
        ->and($result->negativeWeightCount)->toBe(1)
        ->and($result->missingLeverageCount)->toBe(0)
        ->and($result->maxLeverage)->toBe(5)
        ->and($result->leveragedPositionCount)->toBe(2);
});
