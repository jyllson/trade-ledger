<?php

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;

function checkpointEPortfolioPosition(string $positionId, int $weightPpb, string $instrumentId = 'instrument-1'): PortfolioPosition
{
    return new PortfolioPosition(
        positionId: $positionId,
        instrumentId: $instrumentId,
        weight: Percentage::fromPartsPerBillion($weightPpb),
    );
}

/**
 * @param  list<PortfolioPosition>  $positions
 */
function checkpointELivePortfolio(array $positions, int $socialTradesCount = 0): LivePortfolio
{
    return new LivePortfolio(positions: $positions, socialTradesCount: $socialTradesCount);
}

/**
 * @param  list<PositionAllocation>  $allocations
 * @return list<array{0: string, 1: int}>
 */
function checkpointEAllocationSignature(array $allocations): array
{
    return array_map(
        fn (PositionAllocation $allocation): array => [$allocation->positionId, $allocation->weight->partsPerBillion()],
        $allocations,
    );
}

// --- Empty portfolio -------------------------------------------------------

it('produces an empty positions list for toCopyCoverageRequest on an empty portfolio', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect($request->positions)->toBe([]);
    expect($request->unmodeledEntryCount)->toBe(0);
});

it('produces an empty positions list for toCoverageTargetRequest on an empty portfolio', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);

    $request = $adapter->toCoverageTargetRequest(
        $portfolio,
        Percentage::whole(),
        Money::fromCents(100),
        Money::fromCents(0),
    );

    expect($request->positions)->toBe([]);
    expect($request->unmodeledEntryCount)->toBe(0);
});

// --- 1:1 field mapping -------------------------------------------------------

it('maps multiple positions 1:1 with positionId and weight preserved exactly', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('p1', 100_000_000),
        checkpointEPortfolioPosition('p2', 250_000_000),
        checkpointEPortfolioPosition('p3', 650_000_000),
    ]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect(checkpointEAllocationSignature($request->positions))->toBe([
        ['p1', 100_000_000],
        ['p2', 250_000_000],
        ['p3', 650_000_000],
    ]);
});

it('does not reconstruct the Percentage weight — the same instance is passed through', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $weight = Percentage::fromPartsPerBillion(300_000_000);
    $position = new PortfolioPosition(
        positionId: 'p1',
        instrumentId: 'instrument-1',
        weight: $weight,
    );
    $portfolio = checkpointELivePortfolio([$position]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect($request->positions[0]->weight)->toBe($position->weight);
});

it('preserves the original position order', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('third', 10_000_000),
        checkpointEPortfolioPosition('first', 20_000_000),
        checkpointEPortfolioPosition('second', 30_000_000),
    ]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect(array_map(fn (PositionAllocation $a): string => $a->positionId, $request->positions))
        ->toBe(['third', 'first', 'second']);
});

// --- Duplicates, zero, negative weight --------------------------------------

it('keeps a duplicate positionId as a duplicate, not deduplicated', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('dup', 100_000_000),
        checkpointEPortfolioPosition('dup', 200_000_000),
    ]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect($request->positions)->toHaveCount(2);
    expect($request->positions[0]->positionId)->toBe('dup');
    expect($request->positions[1]->positionId)->toBe('dup');
});

it('keeps a zero-weight position present, not filtered out', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('zero', 0),
        checkpointEPortfolioPosition('positive', 500_000_000),
    ]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect($request->positions)->toHaveCount(2);
    expect($request->positions[0]->positionId)->toBe('zero');
    expect($request->positions[0]->weight->isZero())->toBeTrue();
});

it('keeps a negative-weight position present, not filtered out', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('negative', -100_000_000),
        checkpointEPortfolioPosition('positive', 500_000_000),
    ]);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect($request->positions)->toHaveCount(2);
    expect($request->positions[0]->positionId)->toBe('negative');
    expect($request->positions[0]->weight->isNegative())->toBeTrue();
});

// --- socialTradesCount -> unmodeledEntryCount -------------------------------

it('propagates a zero socialTradesCount as unmodeledEntryCount for both request types', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([checkpointEPortfolioPosition('p1', 500_000_000)], socialTradesCount: 0);

    $copyRequest = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));
    $targetRequest = $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), Money::fromCents(100), Money::fromCents(0));

    expect($copyRequest->unmodeledEntryCount)->toBe(0);
    expect($targetRequest->unmodeledEntryCount)->toBe(0);
});

it('propagates a positive socialTradesCount as unmodeledEntryCount for both request types', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([checkpointEPortfolioPosition('p1', 500_000_000)], socialTradesCount: 4);

    $copyRequest = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));
    $targetRequest = $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), Money::fromCents(100), Money::fromCents(0));

    expect($copyRequest->unmodeledEntryCount)->toBe(4);
    expect($targetRequest->unmodeledEntryCount)->toBe(4);
});

// --- Consistency between the two entry points -------------------------------

it('toCopyCoverageRequest and toCoverageTargetRequest produce the same PositionAllocation list for the same portfolio', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('p1', 100_000_000),
        checkpointEPortfolioPosition('p2', 0),
        checkpointEPortfolioPosition('p3', -50_000_000),
        checkpointEPortfolioPosition('p3', -50_000_000),
    ]);

    $copyRequest = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));
    $targetRequest = $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), Money::fromCents(100), Money::fromCents(0));

    expect(checkpointEAllocationSignature($targetRequest->positions))
        ->toBe(checkpointEAllocationSignature($copyRequest->positions));
});

// --- Argument instance identity ---------------------------------------------

it('passes through the exact same Money copyAmount instance', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);
    $copyAmount = Money::fromCents(20_000);

    $request = $adapter->toCopyCoverageRequest($portfolio, $copyAmount, Money::fromCents(100));

    expect($request->copyAmount)->toBe($copyAmount);
});

it('passes through the exact same Money minimumPositionAmount instance for toCopyCoverageRequest', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);
    $minimumPositionAmount = Money::fromCents(100);

    $request = $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), $minimumPositionAmount);

    expect($request->minimumPositionAmount)->toBe($minimumPositionAmount);
});

it('passes through the exact same Money minimumPositionAmount instance for toCoverageTargetRequest', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);
    $minimumPositionAmount = Money::fromCents(100);

    $request = $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), $minimumPositionAmount, Money::fromCents(0));

    expect($request->minimumPositionAmount)->toBe($minimumPositionAmount);
});

it('passes through the exact same Percentage targetCoverage instance', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);
    $targetCoverage = Percentage::fromPartsPerBillion(750_000_000);

    $request = $adapter->toCoverageTargetRequest($portfolio, $targetCoverage, Money::fromCents(100), Money::fromCents(0));

    expect($request->targetCoverage)->toBe($targetCoverage);
});

it('passes through the exact same Money platformMinimumCopyAmount instance', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([]);
    $platformMinimumCopyAmount = Money::fromCents(20_000);

    $request = $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), Money::fromCents(100), $platformMinimumCopyAmount);

    expect($request->platformMinimumCopyAmount)->toBe($platformMinimumCopyAmount);
});

// --- Determinism and immutability -------------------------------------------

it('produces a structurally equal result on a repeated call with the same arguments', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $portfolio = checkpointELivePortfolio([
        checkpointEPortfolioPosition('p1', 100_000_000),
        checkpointEPortfolioPosition('p2', 900_000_000),
    ]);
    $copyAmount = Money::fromCents(20_000);
    $minimumPositionAmount = Money::fromCents(100);

    $first = $adapter->toCopyCoverageRequest($portfolio, $copyAmount, $minimumPositionAmount);
    $second = $adapter->toCopyCoverageRequest($portfolio, $copyAmount, $minimumPositionAmount);

    expect(checkpointEAllocationSignature($second->positions))->toBe(checkpointEAllocationSignature($first->positions));
    expect($second->unmodeledEntryCount)->toBe($first->unmodeledEntryCount);
});

it('does not modify the original portfolio or its positions', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;
    $positions = [
        checkpointEPortfolioPosition('p1', 100_000_000),
        checkpointEPortfolioPosition('p2', 900_000_000),
    ];
    $portfolio = checkpointELivePortfolio($positions, socialTradesCount: 2);

    $before = array_map(
        fn (PortfolioPosition $p): array => [$p->positionId, $p->instrumentId, $p->weight->partsPerBillion()],
        $portfolio->positions,
    );
    $beforeSocialTradesCount = $portfolio->socialTradesCount;

    $adapter->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));
    $adapter->toCoverageTargetRequest($portfolio, Percentage::whole(), Money::fromCents(100), Money::fromCents(0));

    $after = array_map(
        fn (PortfolioPosition $p): array => [$p->positionId, $p->instrumentId, $p->weight->partsPerBillion()],
        $portfolio->positions,
    );

    expect($after)->toBe($before);
    expect($portfolio->socialTradesCount)->toBe($beforeSocialTradesCount);
});

// --- Return type sanity ------------------------------------------------------

it('returns a CopyCoverageRequest instance from toCopyCoverageRequest', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;

    $request = $adapter->toCopyCoverageRequest(checkpointELivePortfolio([]), Money::fromCents(20_000), Money::fromCents(100));

    expect($request)->toBeInstanceOf(CopyCoverageRequest::class);
});

it('returns a CoverageTargetRequest instance from toCoverageTargetRequest', function (): void {
    $adapter = new LivePortfolioCoverageAdapter;

    $request = $adapter->toCoverageTargetRequest(checkpointELivePortfolio([]), Percentage::whole(), Money::fromCents(100), Money::fromCents(0));

    expect($request)->toBeInstanceOf(CoverageTargetRequest::class);
});
