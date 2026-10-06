<?php

declare(strict_types=1);

use App\Analytics\Calculators\ConcentrationCalculator;
use App\Analytics\Data\ConcentrationGroup;
use App\Analytics\Data\ConcentrationWarning;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\ExposureWeightBasis;
use App\Analytics\Data\PortfolioHolding;

require_once __DIR__.'/ExposureFixtures.php';

/**
 * @param  list<ConcentrationGroup>  $groups
 * @return list<array{0: string|null, 1: int, 2: int}>
 */
function concentrationGroupRows(array $groups): array
{
    return array_map(static fn (ConcentrationGroup $group): array => [$group->key, $group->weight->partsPerBillion(), $group->positionCount], $groups);
}

it('sums positions of the same instrument and computes HHI, effective positions, largest and top 3 by instrument', function () {
    $result = (new ConcentrationCalculator)->calculate(referenceExposurePortfolio());
    $dimension = $result->byInstrument;

    // Units of 1/18: 8, 4, 3, 3.
    // HHI = (64 + 16 + 9 + 9) / 324 = 98/324 = 0.302469135802… → 302_469_136 ppb
    // effective = 324/98 = 3.306122448979… → 3.306122449
    // largest = 8/18 = 0.444444444…; top 3 = 15/18 = 0.833333333…
    // tie 3 vs 3: key "1" < "100001"; 3/18 = 0.1666666666|7 → 166_666_667
    expect($dimension->status)->toBe(ExposureStatus::Complete)
        ->and($dimension->hhi->partsPerBillion())->toBe(302_469_136)
        ->and($dimension->effectivePositions)->toBe('3.306122449')
        ->and($dimension->largest->key)->toBe('1001')
        ->and($dimension->largest->weight->partsPerBillion())->toBe(444_444_444)
        ->and($dimension->topThreeWeight->partsPerBillion())->toBe(833_333_333)
        ->and(concentrationGroupRows($dimension->groups))->toBe([
            ['1001', 444_444_444, 2],
            ['100000', 222_222_222, 1],
            ['1', 166_666_667, 1],
            ['100001', 166_666_667, 1],
        ])
        ->and($dimension->classifiedWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($dimension->unclassifiedWeight->partsPerBillion())->toBe(0);
});

it('computes concentration by asset class on the invested-only basis', function () {
    $result = (new ConcentrationCalculator)->calculate(referenceExposurePortfolio());
    $dimension = $result->byAssetClass;

    // Stocks 8, Crypto 7, Currencies 3 (units of 1/18).
    // HHI = (64 + 49 + 9) / 324 = 122/324 = 0.376543209876… → 376_543_210
    // effective = 324/122 = 2.655737704918… → 2.655737705
    // top 3 = 18/18 = 1 (only three classes)
    expect($dimension->status)->toBe(ExposureStatus::Complete)
        ->and($dimension->hhi->partsPerBillion())->toBe(376_543_210)
        ->and($dimension->effectivePositions)->toBe('2.655737705')
        ->and($dimension->largest->key)->toBe('Stocks')
        ->and($dimension->topThreeWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and(concentrationGroupRows($dimension->groups))->toBe([
            ['Stocks', 444_444_444, 2],
            ['Crypto', 388_888_889, 2],
            ['Currencies', 166_666_667, 1],
        ]);
});

it('reports the weight basis, cash and completeness metadata', function () {
    $result = (new ConcentrationCalculator)->calculate(referenceExposurePortfolio());

    // invested 90% + cash 10% = 100% ⇒ unaccounted 0
    expect($result->methodologyVersion)->toBe('concentration-v1')
        ->and($result->weightBasis)->toBe(ExposureWeightBasis::InvestedOnly)
        ->and($result->positionCount)->toBe(5)
        ->and($result->weightedPositionCount)->toBe(5)
        ->and($result->missingWeightCount)->toBe(0)
        ->and($result->negativeWeightCount)->toBe(0)
        ->and($result->investedWeight->partsPerBillion())->toBe(900_000_000)
        ->and($result->cashWeight->partsPerBillion())->toBe(100_000_000)
        ->and($result->unaccountedWeight->partsPerBillion())->toBe(0)
        ->and($result->warnings)->toBe([]);
});

it('reports sector as unavailable when the source has no sector classification', function () {
    $dimension = (new ConcentrationCalculator)->calculate(referenceExposurePortfolio())->bySector;

    expect($dimension->status)->toBe(ExposureStatus::Unavailable)
        ->and($dimension->unavailableReason)->toBe(ExposureUnavailableReason::ClassificationNotSupported)
        ->and($dimension->groups)->toBe([])
        ->and($dimension->hhi)->toBeNull()
        ->and($dimension->effectivePositions)->toBeNull()
        ->and($dimension->largest)->toBeNull()
        ->and($dimension->topThreeWeight)->toBeNull();
});

it('computes sector concentration when the source supports it, with an explicit unknown group', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('s1', '1', 300_000_000, 'Stocks', sector: 'Tech'),
        exposureHolding('s2', '2', 300_000_000, 'Stocks', sector: 'Tech'),
        exposureHolding('s3', '3', 400_000_000, 'Crypto', sector: null),
    ], sectors: true));

    // Tech 60%, unknown 40%: HHI = 0.36 + 0.16 = 0.52; effective = 1/0.52 = 1.923076923|07
    expect($result->bySector->status)->toBe(ExposureStatus::Partial)
        ->and($result->bySector->hhi->partsPerBillion())->toBe(520_000_000)
        ->and($result->bySector->effectivePositions)->toBe('1.923076923')
        ->and(concentrationGroupRows($result->bySector->groups))->toBe([['Tech', 600_000_000, 2], [null, 400_000_000, 1]])
        ->and($result->bySector->unclassifiedWeight->partsPerBillion())->toBe(400_000_000)
        ->and($result->warnings)->toBe([ConcentrationWarning::SectorUnknown]);
});

it('keeps an unknown asset class as an explicit group and marks the dimension partial', function () {
    $result = (new ConcentrationCalculator)->calculate(incompleteExposurePortfolio());
    $dimension = $result->byAssetClass;

    // Usable W = 100%: Stocks 70% (a + c), unknown 30% (b); d has no weight.
    // HHI = 0.49 + 0.09 = 0.58; effective = 1/0.58 = 1.724137931|03
    expect($dimension->status)->toBe(ExposureStatus::Partial)
        ->and($dimension->hhi->partsPerBillion())->toBe(580_000_000)
        ->and($dimension->effectivePositions)->toBe('1.724137931')
        ->and($dimension->largest->key)->toBe('Stocks')
        ->and($dimension->largest->weight->partsPerBillion())->toBe(700_000_000)
        ->and($dimension->topThreeWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and(concentrationGroupRows($dimension->groups))->toBe([['Stocks', 700_000_000, 2], [null, 300_000_000, 1]])
        ->and($dimension->groups[1]->isUnknown())->toBeTrue()
        ->and($dimension->classifiedWeight->partsPerBillion())->toBe(700_000_000)
        ->and($dimension->unclassifiedWeight->partsPerBillion())->toBe(300_000_000);
});

it('excludes a position with a missing weight from the weights but counts and flags it', function () {
    $result = (new ConcentrationCalculator)->calculate(incompleteExposurePortfolio());

    // By instrument: 50%, 30%, 20% ⇒ HHI = 0.25 + 0.09 + 0.04 = 0.38; effective = 2.631578947|36
    expect($result->byInstrument->status)->toBe(ExposureStatus::Complete)
        ->and($result->byInstrument->hhi->partsPerBillion())->toBe(380_000_000)
        ->and($result->byInstrument->effectivePositions)->toBe('2.631578947')
        ->and(array_column(concentrationGroupRows($result->byInstrument->groups), 0))->toBe(['10', '20', '30'])
        ->and($result->positionCount)->toBe(4)
        ->and($result->weightedPositionCount)->toBe(3)
        ->and($result->missingWeightCount)->toBe(1)
        ->and($result->cashWeight)->toBeNull()
        ->and($result->unaccountedWeight)->toBeNull()
        ->and($result->warnings)->toBe([
            ConcentrationWarning::MissingPositionWeight,
            ConcentrationWarning::AssetClassUnknown,
            ConcentrationWarning::CashWeightUnknown,
        ]);
});

it('returns HHI = 1 and one effective position for a single position', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('only', '7', 250_000_000, 'ETF'),
    ], cashPpb: 750_000_000));

    // w = 25% / 25% = 1 ⇒ HHI = 1, effective = 1, largest = top 3 = 1
    foreach ([$result->byInstrument, $result->byAssetClass] as $dimension) {
        expect($dimension->hhi->partsPerBillion())->toBe(1_000_000_000)
            ->and($dimension->effectivePositions)->toBe('1.000000000')
            ->and($dimension->largest->weight->partsPerBillion())->toBe(1_000_000_000)
            ->and($dimension->topThreeWeight->partsPerBillion())->toBe(1_000_000_000);
    }

    expect($result->unaccountedWeight->partsPerBillion())->toBe(0);
});

it('sums all groups for top 3 when there are fewer than three', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('x', '1', 600_000_000, 'Stocks'),
        exposureHolding('y', '2', 400_000_000, 'Stocks'),
    ]));

    // 60% + 40% = 100%; HHI = 0.36 + 0.16 = 0.52
    expect($result->byInstrument->topThreeWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->byInstrument->hhi->partsPerBillion())->toBe(520_000_000)
        ->and($result->byAssetClass->hhi->partsPerBillion())->toBe(1_000_000_000);
});

it('treats a duplicated instrument as one group, not as separate positions', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('d1', '55', 300_000_000, 'Stocks'),
        exposureHolding('d2', '55', 300_000_000, 'Stocks'),
        exposureHolding('d3', '66', 400_000_000, 'Stocks'),
    ]));

    // Per instrument: 60% / 40% ⇒ HHI 0.52 (per position it would be 0.09 + 0.09 + 0.16 = 0.34)
    expect($result->byInstrument->hhi->partsPerBillion())->toBe(520_000_000)
        ->and(concentrationGroupRows($result->byInstrument->groups))->toBe([['55', 600_000_000, 2], ['66', 400_000_000, 1]]);
});

it('returns unavailable dimensions for an empty portfolio', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([], cashPpb: null));

    foreach ([$result->byInstrument, $result->byAssetClass] as $dimension) {
        expect($dimension->status)->toBe(ExposureStatus::Unavailable)
            ->and($dimension->unavailableReason)->toBe(ExposureUnavailableReason::NoInvestedWeight)
            ->and($dimension->hhi)->toBeNull()
            ->and($dimension->effectivePositions)->toBeNull();
    }

    expect($result->positionCount)->toBe(0)
        ->and($result->investedWeight->partsPerBillion())->toBe(0)
        ->and($result->warnings)->toBe([ConcentrationWarning::CashWeightUnknown, ConcentrationWarning::NoInvestedWeight]);
});

it('treats a cash-only portfolio as having no invested weight, never as a cash position', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([], cashPpb: 1_000_000_000));

    expect($result->byInstrument->status)->toBe(ExposureStatus::Unavailable)
        ->and($result->byInstrument->groups)->toBe([])
        ->and($result->cashWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->unaccountedWeight->partsPerBillion())->toBe(0)
        ->and($result->warnings)->toBe([ConcentrationWarning::NoInvestedWeight]);
});

it('reports the asset class dimension as unavailable when no usable weight is classified', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('u1', '1', 500_000_000, null),
        exposureHolding('u2', '2', 500_000_000, null),
    ]));

    expect($result->byAssetClass->status)->toBe(ExposureStatus::Unavailable)
        ->and($result->byAssetClass->unavailableReason)->toBe(ExposureUnavailableReason::NoData)
        ->and($result->byAssetClass->hhi)->toBeNull()
        ->and(concentrationGroupRows($result->byAssetClass->groups))->toBe([[null, 1_000_000_000, 2]])
        ->and($result->byAssetClass->classifiedWeight->partsPerBillion())->toBe(0)
        ->and($result->byAssetClass->unclassifiedWeight->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->byInstrument->status)->toBe(ExposureStatus::Complete);
});

it('ignores a negative weight, counts it and reports the unaccounted remainder', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('ok', '1', 900_000_000, 'Stocks'),
        exposureHolding('bad', '2', -10_000_000, 'Stocks'),
    ], cashPpb: 50_000_000));

    // invested 90% (negative ignored) + cash 5% ⇒ unaccounted 1 − 0.90 − 0.05 = 5%
    expect($result->negativeWeightCount)->toBe(1)
        ->and($result->weightedPositionCount)->toBe(1)
        ->and($result->investedWeight->partsPerBillion())->toBe(900_000_000)
        ->and($result->unaccountedWeight->partsPerBillion())->toBe(50_000_000)
        ->and($result->byInstrument->hhi->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->warnings)->toBe([ConcentrationWarning::NegativeWeightIgnored]);
});

it('reports a negative unaccounted weight when positions and cash exceed the whole', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 950_000_000, 'Stocks'),
    ], cashPpb: 100_000_000));

    expect($result->unaccountedWeight->partsPerBillion())->toBe(-50_000_000);
});

it('keeps zero-weight positions in their group without moving the metrics', function () {
    $result = (new ConcentrationCalculator)->calculate(exposurePortfolio([
        exposureHolding('a', '1', 1_000_000_000, 'Stocks'),
        exposureHolding('z', '2', 0, 'Crypto'),
    ]));

    expect($result->byInstrument->hhi->partsPerBillion())->toBe(1_000_000_000)
        ->and(concentrationGroupRows($result->byAssetClass->groups))->toBe([['Stocks', 1_000_000_000, 1], ['Crypto', 0, 1]]);
});

it('rejects blank identifiers and blank classifications instead of guessing', function (Closure $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'blank instrument' => [fn () => new PortfolioHolding('p', '  ', null)],
    'blank position id' => [fn () => new PortfolioHolding('', '1', null)],
    'blank asset class' => [fn () => new PortfolioHolding('p', '1', null, assetClass: ' ')],
    'blank sector' => [fn () => new PortfolioHolding('p', '1', null, sector: '')],
    'negative cash' => [fn () => exposurePortfolio([], cashPpb: -1)],
]);
