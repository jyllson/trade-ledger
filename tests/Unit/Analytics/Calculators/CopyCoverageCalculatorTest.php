<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\CoverageWarning;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\Data\PositionSkipReason;
use App\Analytics\Exceptions\CoverageCalculationException;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;

function checkpointCPosition(string $positionId, int $weightPpb): PositionAllocation
{
    return new PositionAllocation($positionId, Percentage::fromPartsPerBillion($weightPpb));
}

/**
 * The 16-position fixture-weight scenario from the eToro live-portfolio
 * fixture (percentage points, not loaded from any fixture file — the
 * calculator is source-neutral and must not depend on App\Etoro or its
 * fixtures). ppb = percentage points * 10_000_000.
 *
 * @return list<PositionAllocation>
 */
function checkpointCFixtureWeightPositions(): array
{
    $percentagePointsToPpb = [
        1_000_000, 2_000_000, 4_000_000, 5_000_000, 8_000_000, 10_000_000,
        20_000_000, 30_000_000, 40_000_000, 50_000_000, 70_000_000,
        90_000_000, 110_000_000, 140_000_000, 170_000_000, 250_000_000,
    ];

    return array_map(
        fn (int $index, int $ppb): PositionAllocation => checkpointCPosition('pos'.($index + 1), $ppb),
        array_keys($percentagePointsToPpb),
        $percentagePointsToPpb,
    );
}

// --- Eligibility granice ---------------------------------------------------

it('weight 0.5%, copy $200 is eligible', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 5_000_000)],
    ));

    expect($result->eligibleCount)->toBe(1);
    expect($result->eligiblePositions[0]->minimumCopyAmountForEligibility?->cents())->toBe(20_000);
});

it('weight 0.5%, one cent below the breakpoint, is skipped', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(19_999),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 5_000_000)],
    ));

    expect($result->eligibleCount)->toBe(0);
    expect($result->skippedPositions[0]->reason)->toBe(PositionSkipReason::BelowMinimum);
});

it('weight 0.2%, copy $500 is eligible', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(50_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 2_000_000)],
    ));

    expect($result->eligibleCount)->toBe(1);
});

it('weight 0.1%, copy $1,000 is eligible', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(100_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 1_000_000)],
    ));

    expect($result->eligibleCount)->toBe(1);
});

// --- Fixture-weight scenario -------------------------------------------

it('covers exactly 13 positions and 99.3% weight at a $200 copy amount', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: checkpointCFixtureWeightPositions(),
    ));

    expect($result->eligibleCount)->toBe(13);
    expect($result->skippedCount)->toBe(3);
    expect($result->coveredWeight->partsPerBillion())->toBe(993_000_000);
});

it('covers exactly 15 positions and 99.9% weight at a $500 copy amount', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(50_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: checkpointCFixtureWeightPositions(),
    ));

    expect($result->eligibleCount)->toBe(15);
    expect($result->skippedCount)->toBe(1);
    expect($result->coveredWeight->partsPerBillion())->toBe(999_000_000);
});

it('covers all 16 positions and 100% weight at a $1,000 copy amount', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(100_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: checkpointCFixtureWeightPositions(),
    ));

    expect($result->eligibleCount)->toBe(16);
    expect($result->skippedCount)->toBe(0);
    expect($result->coveredWeight->partsPerBillion())->toBe(1_000_000_000);
});

// --- Dodatno -------------------------------------------------------------

it('breakpoint rounds up to the next cent when division is not exact', function (): void {
    $calculator = new CopyCoverageCalculator;

    // minimumPositionCents=100, weightPpb=300_000_000 (30%):
    // 100 * 1_000_000_000 / 300_000_000 = 333.333... -> ceil = 334 cents.
    $breakpoint = $calculator->minimumAmountForPosition(
        checkpointCPosition('p1', 300_000_000),
        Money::fromCents(100),
    );

    expect($breakpoint?->cents())->toBe(334);
});

it('minimumAmountForPosition returns null for a zero weight', function (): void {
    $calculator = new CopyCoverageCalculator;

    expect($calculator->minimumAmountForPosition(checkpointCPosition('p1', 0), Money::fromCents(100)))->toBeNull();
});

it('minimumAmountForPosition returns null for a negative weight', function (): void {
    $calculator = new CopyCoverageCalculator;

    expect($calculator->minimumAmountForPosition(checkpointCPosition('p1', -1), Money::fromCents(100)))->toBeNull();
});

it('minimumAmountForPosition rejects a non-positive minimumPositionAmount with InvalidArgumentException, regardless of weight', function (int $weightPpb, int $minimumCents): void {
    $calculator = new CopyCoverageCalculator;

    expect(fn () => $calculator->minimumAmountForPosition(checkpointCPosition('p1', $weightPpb), Money::fromCents($minimumCents)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'positive weight + zero minimum' => [500_000_000, 0],
    'positive weight + negative minimum' => [500_000_000, -100],
    'zero weight + zero minimum' => [0, 0],
    'negative weight + negative minimum' => [-500_000_000, -100],
]);

it('a copy amount of 0 leaves every positive-weight position skipped', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 500_000_000)],
    ));

    expect($result->eligibleCount)->toBe(0);
    expect($result->skippedPositions[0]->reason)->toBe(PositionSkipReason::BelowMinimum);
});

it('an empty portfolio produces all-zero counts and weights, with observedWeightDifferenceFromWhole = -whole', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [],
    ));

    expect($result->isEmptyPortfolio)->toBeTrue();
    expect($result->eligibleCount)->toBe(0);
    expect($result->skippedCount)->toBe(0);
    expect($result->coveredWeight->partsPerBillion())->toBe(0);
    expect($result->skippedWeight->partsPerBillion())->toBe(0);
    expect($result->totalObservedWeight->partsPerBillion())->toBe(0);
    expect($result->observedWeightDifferenceFromWhole->partsPerBillion())->toBe(-1_000_000_000);
    expect($result->warnings)->toBe([CoverageWarning::ObservedWeightNotWhole]);
});

it('a non-empty portfolio with only zero weight produces NoPositiveWeightObserved and no eligible positions', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 0), checkpointCPosition('p2', 0)],
    ));

    expect($result->eligibleCount)->toBe(0);
    expect($result->skippedCount)->toBe(2);
    expect($result->isEmptyPortfolio)->toBeFalse();
    expect($result->warnings)->toContain(CoverageWarning::NoPositiveWeightObserved);
});

it('a negative weight is skipped, contributes to totalObservedWeight but not covered/skipped/positive weight', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 500_000_000), checkpointCPosition('p2', -100_000_000)],
    ));

    $negativeOutcome = collect($result->skippedPositions)->firstWhere('positionId', 'p2');

    expect($negativeOutcome->eligible)->toBeFalse();
    expect($negativeOutcome->reason)->toBe(PositionSkipReason::NegativeWeight);
    expect($negativeOutcome->minimumCopyAmountForEligibility)->toBeNull();
    expect($result->coveredWeight->partsPerBillion())->toBe(500_000_000);
    expect($result->skippedWeight->partsPerBillion())->toBe(0);
    expect($result->positiveObservedWeight->partsPerBillion())->toBe(500_000_000);
    expect($result->totalObservedWeight->partsPerBillion())->toBe(400_000_000);
});

it('skippedWeight excludes zero and negative weight, only sums positive BelowMinimum positions', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [
            checkpointCPosition('p1', 500_000_000),
            checkpointCPosition('p2', 0),
            checkpointCPosition('p3', -100_000_000),
        ],
    ));

    expect($result->skippedCount)->toBe(3);
    expect($result->skippedWeight->partsPerBillion())->toBe(500_000_000);
});

it('observedWeightDifferenceFromWhole is the exact signed difference from whole', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(1_000_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 950_000_000)],
    ));

    expect($result->totalObservedWeight->partsPerBillion())->toBe(950_000_000);
    expect($result->observedWeightDifferenceFromWhole->partsPerBillion())->toBe(-50_000_000);
});

it('a duplicate positionId is not deduplicated; both instances are processed', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('dup', 500_000_000), checkpointCPosition('dup', 500_000_000)],
    ));

    expect($result->eligibleCount)->toBe(2);
    expect($result->eligiblePositions[0]->positionId)->toBe('dup');
    expect($result->eligiblePositions[1]->positionId)->toBe('dup');
    expect($result->warnings)->toContain(CoverageWarning::DuplicatePositionId);
});

it('warning order is deterministic and canonical regardless of position insertion order, and each warning appears at most once', function (array $positions): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: $positions,
        unmodeledEntryCount: 1,
    ));

    expect($result->warnings)->toBe([
        CoverageWarning::DuplicatePositionId,
        CoverageWarning::NegativeWeightIgnored,
        CoverageWarning::ObservedWeightNotWhole,
        CoverageWarning::UnmodeledPortfolioEntriesPresent,
        CoverageWarning::NoPositiveWeightObserved,
    ]);
    expect($result->warnings)->toHaveCount(count(array_unique(array_map(fn (CoverageWarning $w): string => $w->value, $result->warnings))));
})->with([
    'insertion order A' => [[
        checkpointCPosition('dup', 0),
        checkpointCPosition('dup', 0),
        checkpointCPosition('neg', -100_000_000),
    ]],
    'insertion order B' => [[
        checkpointCPosition('neg', -100_000_000),
        checkpointCPosition('dup', 0),
        checkpointCPosition('dup', 0),
    ]],
]);

it('unmodeledEntryCount alone produces only the UnmodeledPortfolioEntriesPresent warning', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(1_000_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCPosition('p1', 1_000_000_000)],
        unmodeledEntryCount: 1,
    ));

    expect($result->warnings)->toBe([CoverageWarning::UnmodeledPortfolioEntriesPresent]);
});

it('does not mutate the input request or its positions', function (): void {
    $calculator = new CopyCoverageCalculator;
    $positions = checkpointCFixtureWeightPositions();
    $request = new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: $positions,
    );

    $before = array_map(fn (PositionAllocation $p): array => [$p->positionId, $p->weight->partsPerBillion()], $request->positions);

    $calculator->evaluate($request);

    $after = array_map(fn (PositionAllocation $p): array => [$p->positionId, $p->weight->partsPerBillion()], $request->positions);

    expect($after)->toBe($before);
});

it('two calls with the same request produce an identical result', function (): void {
    $calculator = new CopyCoverageCalculator;
    $request = new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: checkpointCFixtureWeightPositions(),
    );

    $first = $calculator->evaluate($request);
    $second = $calculator->evaluate($request);

    expect($second->eligibleCount)->toBe($first->eligibleCount);
    expect($second->skippedCount)->toBe($first->skippedCount);
    expect($second->coveredWeight->partsPerBillion())->toBe($first->coveredWeight->partsPerBillion());
    expect($second->warnings)->toBe($first->warnings);
});

// --- Target coverage -------------------------------------------------------

it('target coverage is relative to positiveObservedWeight, not the nominal whole', function (): void {
    $calculator = new CopyCoverageCalculator;

    // Two positive positions (30% + 20% = 50% positive observed weight) and
    // one negative position; a 100% target must be satisfiable purely from
    // the 50% positive slice, not blocked by the nominal total being 40%.
    $result = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: [
            checkpointCPosition('p1', 300_000_000),
            checkpointCPosition('p2', 200_000_000),
            checkpointCPosition('neg', -100_000_000),
        ],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result->achievedRatio?->partsPerBillion())->toBe(1_000_000_000);
    expect($result->coveredRawWeight?->partsPerBillion())->toBe(500_000_000);
    expect($result->hasIncompleteSourceData)->toBeTrue();
});

it('targetAbsolute uses ceiling and achievedRatio uses floor, selecting the correct breakpoint per target', function (): void {
    $calculator = new CopyCoverageCalculator;

    // p1=60% (breakpoint $1.67), p2=30% (breakpoint $3.34), p3=10% (breakpoint $10.00).
    $positions = [
        checkpointCPosition('p1', 600_000_000),
        checkpointCPosition('p2', 300_000_000),
        checkpointCPosition('p3', 100_000_000),
    ];

    $result50 = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(500_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result50->mathematicalMinimumCopyAmount?->cents())->toBe(167);
    expect($result50->achievedRatio?->partsPerBillion())->toBe(600_000_000);

    $result65 = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(650_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result65->mathematicalMinimumCopyAmount?->cents())->toBe(334);
    expect($result65->achievedRatio?->partsPerBillion())->toBe(900_000_000);

    $result95 = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(950_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result95->mathematicalMinimumCopyAmount?->cents())->toBe(1_000);
    expect($result95->achievedRatio?->partsPerBillion())->toBe(1_000_000_000);
});

it('a platform minimum above the mathematical minimum raises effectiveMinimumCopyAmount and actually increases coverage', function (): void {
    $calculator = new CopyCoverageCalculator;

    // p1=60% (breakpoint $1.67), p2=30% (breakpoint $3.34), p3=10% (breakpoint $10.00).
    $positions = [
        checkpointCPosition('p1', 600_000_000),
        checkpointCPosition('p2', 300_000_000),
        checkpointCPosition('p3', 100_000_000),
    ];

    // A 50% target is already satisfied by p1 alone (60% >= 50%), so
    // without a platform minimum the mathematical minimum ($1.67) is also
    // the effective minimum, and only p1 is covered.
    $withoutPlatformFloor = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(500_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($withoutPlatformFloor->mathematicalMinimumCopyAmount?->cents())->toBe(167);
    expect($withoutPlatformFloor->effectiveMinimumCopyAmount?->cents())->toBe(167);
    expect($withoutPlatformFloor->achievedRatio?->partsPerBillion())->toBe(600_000_000);

    // A platform minimum of $3.34 forces the effective minimum above the
    // mathematical minimum, which now also covers p2 ($3.34 <= $3.34),
    // raising actual coverage from 60% to 90% — proving the platform floor
    // changes coverage, not just the reported amount.
    $withPlatformFloor = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(500_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(334),
    ));

    expect($withPlatformFloor->mathematicalMinimumCopyAmount?->cents())->toBe(167);
    expect($withPlatformFloor->effectiveMinimumCopyAmount?->cents())->toBe(334);
    expect($withPlatformFloor->coveredRawWeight?->partsPerBillion())->toBe(900_000_000);
    expect($withPlatformFloor->achievedRatio?->partsPerBillion())->toBe(900_000_000);
});

it('a 100% target covers all positive visible positions', function (): void {
    $calculator = new CopyCoverageCalculator;

    $positions = checkpointCFixtureWeightPositions();

    $result = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result->achievedRatio?->partsPerBillion())->toBe(1_000_000_000);
    expect($result->mathematicalMinimumCopyAmount?->cents())->toBe(100_000);
});

it('no positive positions produces a fully-null coverage result but still computes warnings', function (): void {
    $calculator = new CopyCoverageCalculator;

    $result = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: [checkpointCPosition('p1', 0), checkpointCPosition('p2', -100_000_000)],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result->mathematicalMinimumCopyAmount)->toBeNull();
    expect($result->effectiveMinimumCopyAmount)->toBeNull();
    expect($result->achievedRatio)->toBeNull();
    expect($result->coveredRawWeight)->toBeNull();
    expect($result->hasIncompleteSourceData)->toBeTrue();
    expect($result->warnings)->not->toBeEmpty();
});

it('equal-breakpoint positions activate together at the same copy amount', function (): void {
    $calculator = new CopyCoverageCalculator;

    // Both positions have an identical 10% weight, so an identical $10.00
    // breakpoint; reaching it must make both eligible simultaneously.
    $positions = [
        checkpointCPosition('tie1', 100_000_000),
        checkpointCPosition('tie2', 100_000_000),
    ];

    $result = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(1),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result->mathematicalMinimumCopyAmount?->cents())->toBe(1_000);
    expect($result->achievedRatio?->partsPerBillion())->toBe(1_000_000_000);
    expect($result->coveredRawWeight?->partsPerBillion())->toBe(200_000_000);
});

it('a target not requiring an extreme tiny position succeeds even though that position\'s breakpoint exceeds PHP_INT_MAX', function (): void {
    $calculator = new CopyCoverageCalculator;

    $positions = [
        checkpointCPosition('big', 999_999_999),
        checkpointCPosition('tiny', 1),
    ];

    $result = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::fromPartsPerBillion(990_000_000),
        minimumPositionAmount: Money::fromCents(10_000_000_000),
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    expect($result->mathematicalMinimumCopyAmount)->not->toBeNull();
    expect($result->achievedRatio?->partsPerBillion())->toBeGreaterThanOrEqual(990_000_000);
});

it('a target requiring an extreme tiny position throws a controlled CoverageCalculationException', function (): void {
    $calculator = new CopyCoverageCalculator;

    $positions = [
        checkpointCPosition('big', 999_999_999),
        checkpointCPosition('tiny', 1),
    ];

    expect(fn () => $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: $positions,
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(10_000_000_000),
        platformMinimumCopyAmount: Money::fromCents(0),
    )))->toThrow(CoverageCalculationException::class);
});

it('minimumAmountForPosition throws a controlled error when the result exceeds PHP_INT_MAX', function (): void {
    $calculator = new CopyCoverageCalculator;

    expect(fn () => $calculator->minimumAmountForPosition(
        checkpointCPosition('tiny', 1),
        Money::fromCents(10_000_000_000),
    ))->toThrow(CoverageCalculationException::class);
});

it('a weight sum outside the signed PHP int range throws a controlled error', function (): void {
    $calculator = new CopyCoverageCalculator;

    $positions = [
        checkpointCPosition('p1', PHP_INT_MAX),
        checkpointCPosition('p2', PHP_INT_MAX),
    ];

    expect(fn () => $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(1),
        minimumPositionAmount: Money::fromCents(1),
        positions: $positions,
    )))->toThrow(CoverageCalculationException::class);
});

it('CoverageCalculationException messages do not contain the positionId or numeric inputs', function (): void {
    $calculator = new CopyCoverageCalculator;

    try {
        $calculator->minimumAmountForPosition(
            checkpointCPosition('sentinel-position-id', 1),
            Money::fromCents(10_000_000_000),
        );

        expect(false)->toBeTrue('Expected a CoverageCalculationException to be thrown.');
    } catch (CoverageCalculationException $exception) {
        expect($exception->getMessage())->not->toContain('sentinel-position-id');
        expect($exception->getMessage())->not->toContain('10000000000');
        expect($exception->getMessage())->not->toContain('1000000000000000000');
    }
});

it('CoverageCalculationException is final, has a private constructor, and only generic factory messages', function (): void {
    $reflection = new ReflectionClass(CoverageCalculationException::class);

    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();
    expect($constructor->isPrivate())->toBeTrue();

    expect(CoverageCalculationException::moneyResultOutOfRange()->getMessage())
        ->toBe('Copy coverage calculation produced a money amount outside the representable range.');
    expect(CoverageCalculationException::percentageResultOutOfRange()->getMessage())
        ->toBe('Copy coverage calculation produced a percentage value outside the representable range.');
});

it('CoverageCalculationException cannot be constructed directly with an arbitrary message', function (): void {
    $reflection = new ReflectionClass(CoverageCalculationException::class);

    expect(fn () => $reflection->newInstance('arbitrary attacker-controlled message'))->toThrow(ReflectionException::class);
});

// --- Global BCMath scale independence ------------------------------------

it('does not depend on the global BCMath scale (bcscale())', function (): void {
    $calculator = new CopyCoverageCalculator;

    // weight 500_000_000 ppb, minimumPositionAmount 100 cents: an exactly
    // divisible breakpoint of ceil(100 * 1_000_000_000 / 500_000_000) = 200
    // cents, so a global-scale bug can only manifest as extra precision
    // leaking in, not as a genuinely different exact answer.
    $position = checkpointCPosition('p1', 500_000_000);
    $minimumPositionAmount = Money::fromCents(100);

    // Baseline, computed before the global scale is ever touched.
    $baselineBreakpoint = $calculator->minimumAmountForPosition($position, $minimumPositionAmount);

    $baselineEvaluate = $calculator->evaluate(new CopyCoverageRequest(
        copyAmount: Money::fromCents(200),
        minimumPositionAmount: $minimumPositionAmount,
        positions: [$position],
    ));

    $baselineTarget = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
        positions: [$position],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: $minimumPositionAmount,
        platformMinimumCopyAmount: Money::fromCents(0),
    ));

    $originalScale = bcscale();
    bcscale(4);

    try {
        // A. minimumAmountForPosition()
        $breakpoint = $calculator->minimumAmountForPosition($position, $minimumPositionAmount);

        expect($breakpoint?->cents())->toBe(200);
        expect($breakpoint?->cents())->toBe($baselineBreakpoint?->cents());

        // B. evaluate()
        $evaluated = $calculator->evaluate(new CopyCoverageRequest(
            copyAmount: Money::fromCents(200),
            minimumPositionAmount: $minimumPositionAmount,
            positions: [$position],
        ));

        expect($evaluated->eligibleCount)->toBe(1);
        expect($evaluated->eligiblePositions[0]->eligible)->toBeTrue();
        expect($evaluated->eligiblePositions[0]->minimumCopyAmountForEligibility?->cents())->toBe(200);
        expect($evaluated->eligiblePositions[0]->minimumCopyAmountForEligibility?->cents())
            ->toBe($baselineEvaluate->eligiblePositions[0]->minimumCopyAmountForEligibility?->cents());

        // C. minimumAmountForCoverage()
        $target = $calculator->minimumAmountForCoverage(new CoverageTargetRequest(
            positions: [$position],
            targetCoverage: Percentage::whole(),
            minimumPositionAmount: $minimumPositionAmount,
            platformMinimumCopyAmount: Money::fromCents(0),
        ));

        expect($target->mathematicalMinimumCopyAmount?->cents())->toBe(200);
        expect($target->effectiveMinimumCopyAmount?->cents())->toBe(200);
        expect($target->achievedRatio?->partsPerBillion())->toBe(Percentage::whole()->partsPerBillion());

        expect($target->mathematicalMinimumCopyAmount?->cents())->toBe($baselineTarget->mathematicalMinimumCopyAmount?->cents());
        expect($target->effectiveMinimumCopyAmount?->cents())->toBe($baselineTarget->effectiveMinimumCopyAmount?->cents());
        expect($target->achievedRatio?->partsPerBillion())->toBe($baselineTarget->achievedRatio?->partsPerBillion());
    } finally {
        bcscale($originalScale);
    }

    expect(bcscale())->toBe($originalScale);
});
