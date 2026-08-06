<?php

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CopyCoverageResult;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\CoverageWarning;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\Data\PositionCoverageOutcome;
use App\Analytics\Data\PositionSkipReason;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;

// --- PositionAllocation ---------------------------------------------------

it('PositionAllocation accepts a valid positionId and weight', function (): void {
    $allocation = new PositionAllocation('700001', Percentage::fromPartsPerBillion(1_000_000));

    expect($allocation->positionId)->toBe('700001');
    expect($allocation->weight->partsPerBillion())->toBe(1_000_000);
});

it('PositionAllocation rejects a blank or whitespace-only positionId', function (string $blank): void {
    expect(fn () => new PositionAllocation($blank, Percentage::zero()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'spaces only' => ['   '],
    'tabs and newlines' => ["\t\n"],
]);

it('PositionAllocation rejects a positionId containing a NUL byte', function (string $raw): void {
    expect(fn () => new PositionAllocation($raw, Percentage::zero()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'NUL at the start' => ["\x00700001"],
    'NUL in the middle' => ["700\x00001"],
    'NUL at the end' => ["700001\x00"],
]);

it('PositionAllocation constructor does not trim or otherwise modify a valid positionId', function (): void {
    $allocation = new PositionAllocation('  700001  ', Percentage::zero());

    expect($allocation->positionId)->toBe('  700001  ');
});

it('PositionAllocation accepts a positive, zero, and negative weight', function (int $ppb): void {
    $allocation = new PositionAllocation('700001', Percentage::fromPartsPerBillion($ppb));

    expect($allocation->weight->partsPerBillion())->toBe($ppb);
})->with([
    'positive' => [1_000_000],
    'zero' => [0],
    'negative' => [-1_000_000],
]);

it('PositionAllocation is immutable', function (): void {
    $allocation = new PositionAllocation('700001', Percentage::zero());

    $property = new ReflectionProperty($allocation, 'positionId');

    expect(fn () => $property->setValue($allocation, '999'))->toThrow(Error::class);
    expect($allocation->positionId)->toBe('700001');
});

// --- CopyCoverageRequest ---------------------------------------------------

function checkpointCAllocation(string $positionId = '700001', int $weightPpb = 1_000_000): PositionAllocation
{
    return new PositionAllocation($positionId, Percentage::fromPartsPerBillion($weightPpb));
}

it('CopyCoverageRequest accepts valid arguments', function (): void {
    $request = new CopyCoverageRequest(
        copyAmount: Money::fromCents(20_000),
        minimumPositionAmount: Money::fromCents(100),
        positions: [checkpointCAllocation()],
    );

    expect($request->copyAmount->cents())->toBe(20_000);
    expect($request->minimumPositionAmount->cents())->toBe(100);
    expect($request->positions)->toHaveCount(1);
    expect($request->unmodeledEntryCount)->toBe(0);
});

it('CopyCoverageRequest rejects a non-list positions array', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [1 => checkpointCAllocation()],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest rejects a positions element of the wrong type', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: ['not a position'],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest rejects a negative copyAmount', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(-1),
        minimumPositionAmount: Money::fromCents(100),
        positions: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest accepts a zero copyAmount', function (): void {
    $request = new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [],
    );

    expect($request->copyAmount->isZero())->toBeTrue();
});

it('CopyCoverageRequest rejects a minimumPositionAmount of zero', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(0),
        positions: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest rejects a negative minimumPositionAmount', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(-100),
        positions: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest rejects a negative unmodeledEntryCount', function (): void {
    expect(fn () => new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [],
        unmodeledEntryCount: -1,
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageRequest is immutable', function (): void {
    $request = new CopyCoverageRequest(
        copyAmount: Money::fromCents(0),
        minimumPositionAmount: Money::fromCents(100),
        positions: [],
    );

    $property = new ReflectionProperty($request, 'positions');

    expect(fn () => $property->setValue($request, [checkpointCAllocation()]))->toThrow(Error::class);
});

// --- CoverageTargetRequest ---------------------------------------------------

it('CoverageTargetRequest accepts valid arguments', function (): void {
    $request = new CoverageTargetRequest(
        positions: [checkpointCAllocation()],
        targetCoverage: Percentage::fromPartsPerBillion(500_000_000),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    );

    expect($request->targetCoverage->partsPerBillion())->toBe(500_000_000);
});

it('CoverageTargetRequest rejects a non-list positions array', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [1 => checkpointCAllocation()],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a positions element of the wrong type', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: ['not a position'],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a zero targetCoverage', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::zero(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a negative targetCoverage', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::fromPartsPerBillion(-1),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a targetCoverage above whole', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::fromPartsPerBillion(1_000_000_001),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest accepts a targetCoverage of exactly whole', function (): void {
    $request = new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    );

    expect($request->targetCoverage->partsPerBillion())->toBe(1_000_000_000);
});

it('CoverageTargetRequest rejects a minimumPositionAmount of zero', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(0),
        platformMinimumCopyAmount: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a negative platformMinimumCopyAmount', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(-1),
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest rejects a negative unmodeledEntryCount', function (): void {
    expect(fn () => new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
        unmodeledEntryCount: -1,
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetRequest is immutable', function (): void {
    $request = new CoverageTargetRequest(
        positions: [],
        targetCoverage: Percentage::whole(),
        minimumPositionAmount: Money::fromCents(100),
        platformMinimumCopyAmount: Money::fromCents(0),
    );

    $property = new ReflectionProperty($request, 'positions');

    expect(fn () => $property->setValue($request, [checkpointCAllocation()]))->toThrow(Error::class);
});

// --- PositionCoverageOutcome ---------------------------------------------------

it('PositionCoverageOutcome accepts an eligible positive-weight outcome', function (): void {
    $outcome = new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: true,
        reason: null,
        minimumCopyAmountForEligibility: Money::fromCents(20_000),
    );

    expect($outcome->eligible)->toBeTrue();
    expect($outcome->reason)->toBeNull();
});

it('PositionCoverageOutcome accepts a BelowMinimum positive-weight outcome', function (): void {
    $outcome = new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: false,
        reason: PositionSkipReason::BelowMinimum,
        minimumCopyAmountForEligibility: Money::fromCents(20_000),
    );

    expect($outcome->eligible)->toBeFalse();
    expect($outcome->reason)->toBe(PositionSkipReason::BelowMinimum);
});

it('PositionCoverageOutcome rejects a blank or NUL positionId', function (string $raw): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: $raw,
        weight: Percentage::zero(),
        eligible: false,
        reason: PositionSkipReason::ZeroWeight,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'blank' => ['   '],
    'NUL byte' => ["700\0001"],
]);

it('PositionCoverageOutcome rejects eligible=true with a non-null reason', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: true,
        reason: PositionSkipReason::BelowMinimum,
        minimumCopyAmountForEligibility: Money::fromCents(20_000),
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects eligible=false with a null reason', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: false,
        reason: null,
        minimumCopyAmountForEligibility: Money::fromCents(20_000),
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects eligible=true for a non-positive weight, even with reason=null and minimumCopyAmountForEligibility=null', function (Percentage $weight): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: $weight,
        eligible: true,
        reason: null,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'zero weight' => [Percentage::zero()],
    'negative weight' => [Percentage::fromPartsPerBillion(-1)],
]);

it('PositionCoverageOutcome rejects a positive weight without minimumCopyAmountForEligibility', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: true,
        reason: null,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects a positive weight with a zero minimumCopyAmountForEligibility', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: true,
        reason: null,
        minimumCopyAmountForEligibility: Money::fromCents(0),
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects a non-positive weight with a non-null minimumCopyAmountForEligibility', function (Percentage $weight, PositionSkipReason $reason): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: $weight,
        eligible: false,
        reason: $reason,
        minimumCopyAmountForEligibility: Money::fromCents(1),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'zero weight' => [Percentage::zero(), PositionSkipReason::ZeroWeight],
    'negative weight' => [Percentage::fromPartsPerBillion(-1), PositionSkipReason::NegativeWeight],
]);

it('PositionCoverageOutcome rejects BelowMinimum for a non-positive weight', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::zero(),
        eligible: false,
        reason: PositionSkipReason::BelowMinimum,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects ZeroWeight for a non-zero weight', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::fromPartsPerBillion(-1),
        eligible: false,
        reason: PositionSkipReason::ZeroWeight,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome rejects NegativeWeight for a non-negative weight', function (): void {
    expect(fn () => new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::zero(),
        eligible: false,
        reason: PositionSkipReason::NegativeWeight,
        minimumCopyAmountForEligibility: null,
    ))->toThrow(InvalidArgumentException::class);
});

it('PositionCoverageOutcome is immutable', function (): void {
    $outcome = new PositionCoverageOutcome(
        positionId: '700001',
        weight: Percentage::zero(),
        eligible: false,
        reason: PositionSkipReason::ZeroWeight,
        minimumCopyAmountForEligibility: null,
    );

    $property = new ReflectionProperty($outcome, 'positionId');

    expect(fn () => $property->setValue($outcome, '999'))->toThrow(Error::class);
});

// --- CopyCoverageResult ---------------------------------------------------

function checkpointCEligibleOutcome(string $positionId = '700001'): PositionCoverageOutcome
{
    return new PositionCoverageOutcome(
        positionId: $positionId,
        weight: Percentage::fromPartsPerBillion(5_000_000),
        eligible: true,
        reason: null,
        minimumCopyAmountForEligibility: Money::fromCents(20_000),
    );
}

function checkpointCSkippedOutcome(string $positionId = '700002'): PositionCoverageOutcome
{
    return new PositionCoverageOutcome(
        positionId: $positionId,
        weight: Percentage::zero(),
        eligible: false,
        reason: PositionSkipReason::ZeroWeight,
        minimumCopyAmountForEligibility: null,
    );
}

it('CopyCoverageResult accepts consistent counts and weight sums', function (): void {
    $result = new CopyCoverageResult(
        eligibleCount: 1,
        skippedCount: 1,
        coveredWeight: Percentage::fromPartsPerBillion(5_000_000),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        positiveObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        observedWeightDifferenceFromWhole: Percentage::fromPartsPerBillion(5_000_000 - 1_000_000_000),
        isEmptyPortfolio: false,
        eligiblePositions: [checkpointCEligibleOutcome()],
        skippedPositions: [checkpointCSkippedOutcome()],
        warnings: [],
    );

    expect($result->eligibleCount)->toBe(1);
    expect($result->skippedCount)->toBe(1);
});

it('CopyCoverageResult rejects a mismatched eligibleCount', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 2,
        skippedCount: 0,
        coveredWeight: Percentage::fromPartsPerBillion(5_000_000),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        positiveObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: false,
        eligiblePositions: [checkpointCEligibleOutcome()],
        skippedPositions: [],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects isEmptyPortfolio=true with a non-empty outcome list', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 1,
        skippedCount: 0,
        coveredWeight: Percentage::fromPartsPerBillion(5_000_000),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        positiveObservedWeight: Percentage::fromPartsPerBillion(5_000_000),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: true,
        eligiblePositions: [checkpointCEligibleOutcome()],
        skippedPositions: [],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects isEmptyPortfolio=false with both outcome lists empty', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: false,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects an eligible outcome inside skippedPositions', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 1,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: false,
        eligiblePositions: [],
        skippedPositions: [checkpointCEligibleOutcome()],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects a skipped outcome inside eligiblePositions', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 1,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: false,
        eligiblePositions: [checkpointCSkippedOutcome()],
        skippedPositions: [],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects positiveObservedWeight not equal to coveredWeight + skippedWeight', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::fromPartsPerBillion(1_000_000),
        skippedWeight: Percentage::fromPartsPerBillion(2_000_000),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::fromPartsPerBillion(999),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: false,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects a duplicate warning', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: true,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [CoverageWarning::ObservedWeightNotWhole, CoverageWarning::ObservedWeightNotWhole],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult rejects warnings out of canonical order', function (): void {
    expect(fn () => new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: true,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [CoverageWarning::UnmodeledPortfolioEntriesPresent, CoverageWarning::DuplicatePositionId],
    ))->toThrow(InvalidArgumentException::class);
});

it('CopyCoverageResult accepts warnings already in canonical order', function (): void {
    $result = new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: true,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [CoverageWarning::DuplicatePositionId, CoverageWarning::NoPositiveWeightObserved],
    );

    expect($result->warnings)->toBe([CoverageWarning::DuplicatePositionId, CoverageWarning::NoPositiveWeightObserved]);
});

it('CopyCoverageResult is immutable', function (): void {
    $result = new CopyCoverageResult(
        eligibleCount: 0,
        skippedCount: 0,
        coveredWeight: Percentage::zero(),
        skippedWeight: Percentage::zero(),
        totalObservedWeight: Percentage::zero(),
        positiveObservedWeight: Percentage::zero(),
        observedWeightDifferenceFromWhole: Percentage::zero(),
        isEmptyPortfolio: true,
        eligiblePositions: [],
        skippedPositions: [],
        warnings: [],
    );

    $property = new ReflectionProperty($result, 'eligiblePositions');

    expect(fn () => $property->setValue($result, [checkpointCEligibleOutcome()]))->toThrow(Error::class);
});

// --- CoverageTargetResult ---------------------------------------------------

it('CoverageTargetResult accepts a fully-null result', function (): void {
    $result = new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::whole(),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [],
    );

    expect($result->mathematicalMinimumCopyAmount)->toBeNull();
});

it('CoverageTargetResult rejects a null mathematicalMinimumCopyAmount with a non-null companion field', function (?Money $effective, ?Percentage $achieved, ?Percentage $covered): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: $effective,
        targetRatio: Percentage::whole(),
        achievedRatio: $achieved,
        coveredRawWeight: $covered,
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'effective set' => [Money::fromCents(1), null, null],
    'achieved set' => [null, Percentage::zero(), null],
    'covered set' => [null, null, Percentage::zero()],
]);

it('CoverageTargetResult rejects a non-null mathematicalMinimumCopyAmount with a null companion field', function (?Money $effective, ?Percentage $achieved, ?Percentage $covered): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: $effective,
        targetRatio: Percentage::whole(),
        achievedRatio: $achieved,
        coveredRawWeight: $covered,
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'effective missing' => [null, Percentage::zero(), Percentage::zero()],
    'achieved missing' => [Money::fromCents(100), null, Percentage::zero()],
    'covered missing' => [Money::fromCents(100), Percentage::zero(), null],
]);

it('CoverageTargetResult rejects effectiveMinimumCopyAmount below mathematicalMinimumCopyAmount', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: Money::fromCents(99),
        targetRatio: Percentage::whole(),
        achievedRatio: Percentage::zero(),
        coveredRawWeight: Percentage::zero(),
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult accepts effectiveMinimumCopyAmount equal to mathematicalMinimumCopyAmount', function (): void {
    $result = new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: Money::fromCents(100),
        targetRatio: Percentage::whole(),
        achievedRatio: Percentage::whole(),
        coveredRawWeight: Percentage::whole(),
        hasIncompleteSourceData: false,
        warnings: [],
    );

    expect($result->effectiveMinimumCopyAmount?->compareTo($result->mathematicalMinimumCopyAmount))->toBe(0);
});

it('CoverageTargetResult accepts effectiveMinimumCopyAmount above mathematicalMinimumCopyAmount', function (): void {
    $result = new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: Money::fromCents(500),
        targetRatio: Percentage::whole(),
        achievedRatio: Percentage::whole(),
        coveredRawWeight: Percentage::whole(),
        hasIncompleteSourceData: false,
        warnings: [],
    );

    expect($result->effectiveMinimumCopyAmount->cents())->toBe(500);
});

it('CoverageTargetResult rejects a targetRatio of zero or below', function (int $ppb): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::fromPartsPerBillion($ppb),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

it('CoverageTargetResult rejects a targetRatio above whole', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::fromPartsPerBillion(1_000_000_001),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult rejects a negative achievedRatio', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: Money::fromCents(100),
        targetRatio: Percentage::whole(),
        achievedRatio: Percentage::fromPartsPerBillion(-1),
        coveredRawWeight: Percentage::zero(),
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult rejects an achievedRatio above whole', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: Money::fromCents(100),
        effectiveMinimumCopyAmount: Money::fromCents(100),
        targetRatio: Percentage::whole(),
        achievedRatio: Percentage::fromPartsPerBillion(1_000_000_001),
        coveredRawWeight: Percentage::zero(),
        hasIncompleteSourceData: false,
        warnings: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult rejects a duplicate warning', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::whole(),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [CoverageWarning::NegativeWeightIgnored, CoverageWarning::NegativeWeightIgnored],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult rejects warnings out of canonical order', function (): void {
    expect(fn () => new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::whole(),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [CoverageWarning::NoPositiveWeightObserved, CoverageWarning::NegativeWeightIgnored],
    ))->toThrow(InvalidArgumentException::class);
});

it('CoverageTargetResult does not use an isEstimate property name', function (): void {
    $propertyNames = collect((new ReflectionClass(CoverageTargetResult::class))->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->contains('isEstimate'))->toBeFalse();
});

it('CoverageTargetResult is immutable', function (): void {
    $result = new CoverageTargetResult(
        mathematicalMinimumCopyAmount: null,
        effectiveMinimumCopyAmount: null,
        targetRatio: Percentage::whole(),
        achievedRatio: null,
        coveredRawWeight: null,
        hasIncompleteSourceData: false,
        warnings: [],
    );

    $property = new ReflectionProperty($result, 'warnings');

    expect(fn () => $property->setValue($result, [CoverageWarning::DuplicatePositionId]))->toThrow(Error::class);
});
