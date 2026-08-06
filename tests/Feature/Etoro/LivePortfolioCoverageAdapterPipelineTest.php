<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Data\PositionCoverageOutcome;
use App\Analytics\ValueObjects\Money;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\Mappers\LivePortfolioMapper;

/**
 * Loads a fresh decode of the synthetic fixture on every call, via plain
 * file_get_contents/json_decode (no HTTP, no EtoroClient, no API call) —
 * same approach as the existing Checkpoint B mapper tests.
 *
 * @return array<string, mixed>
 */
function checkpointELivePortfolioFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../Fixtures/Etoro/live-portfolio.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function checkpointEPositionIds(array $outcomes): array
{
    return array_map(fn (PositionCoverageOutcome $outcome): string => $outcome->positionId, $outcomes);
}

// Ascending-investmentPct fixture order (see tests/Fixtures/Etoro/README.md):
// 0.1, 0.2, 0.4, 0.5, 0.8, 1, 2, 3, 4, 5, 7, 9, 11, 14, 17, 25 -> positionId
// 700001..700016 in that exact order.

it('pipeline: $200 copy amount covers exactly 13 positions and 99.3% weight', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointELivePortfolioFixture());
    $request = (new LivePortfolioCoverageAdapter)->toCopyCoverageRequest(
        $portfolio,
        Money::fromCents(20_000),
        Money::fromCents(100),
    );

    $result = (new CopyCoverageCalculator)->evaluate($request);

    expect($result->eligibleCount)->toBe(13);
    expect($result->skippedCount)->toBe(3);
    expect($result->coveredWeight->partsPerBillion())->toBe(993_000_000);

    expect(checkpointEPositionIds($result->eligiblePositions))->toBe([
        '700004', '700005', '700006', '700007', '700008', '700009',
        '700010', '700011', '700012', '700013', '700014', '700015', '700016',
    ]);
    expect(checkpointEPositionIds($result->skippedPositions))->toBe(['700001', '700002', '700003']);
});

it('pipeline: $500 copy amount covers exactly 15 positions and 99.9% weight', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointELivePortfolioFixture());
    $request = (new LivePortfolioCoverageAdapter)->toCopyCoverageRequest(
        $portfolio,
        Money::fromCents(50_000),
        Money::fromCents(100),
    );

    $result = (new CopyCoverageCalculator)->evaluate($request);

    expect($result->eligibleCount)->toBe(15);
    expect($result->skippedCount)->toBe(1);
    expect($result->coveredWeight->partsPerBillion())->toBe(999_000_000);

    expect(checkpointEPositionIds($result->eligiblePositions))->toBe([
        '700002', '700003', '700004', '700005', '700006', '700007', '700008',
        '700009', '700010', '700011', '700012', '700013', '700014', '700015', '700016',
    ]);
    expect(checkpointEPositionIds($result->skippedPositions))->toBe(['700001']);
});

it('pipeline: $1,000 copy amount covers all 16 positions and 100% weight', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointELivePortfolioFixture());
    $request = (new LivePortfolioCoverageAdapter)->toCopyCoverageRequest(
        $portfolio,
        Money::fromCents(100_000),
        Money::fromCents(100),
    );

    $result = (new CopyCoverageCalculator)->evaluate($request);

    expect($result->eligibleCount)->toBe(16);
    expect($result->skippedCount)->toBe(0);
    expect($result->coveredWeight->partsPerBillion())->toBe(1_000_000_000);

    expect(checkpointEPositionIds($result->eligiblePositions))->toBe([
        '700001', '700002', '700003', '700004', '700005', '700006', '700007', '700008',
        '700009', '700010', '700011', '700012', '700013', '700014', '700015', '700016',
    ]);
    expect($result->skippedPositions)->toBe([]);
});

it('pipeline: the adapter does not lose any position between LivePortfolio and CopyCoverageRequest', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointELivePortfolioFixture());
    $request = (new LivePortfolioCoverageAdapter)->toCopyCoverageRequest(
        $portfolio,
        Money::fromCents(20_000),
        Money::fromCents(100),
    );

    expect($portfolio->positions)->toHaveCount(16);
    expect($request->positions)->toHaveCount(16);
});

it('pipeline: does not mutate the decoded fixture payload', function (): void {
    $fixture = checkpointELivePortfolioFixture();
    $originalJson = json_encode($fixture);

    $portfolio = (new LivePortfolioMapper)->map($fixture);
    (new LivePortfolioCoverageAdapter)->toCopyCoverageRequest($portfolio, Money::fromCents(20_000), Money::fromCents(100));

    expect(json_encode($fixture))->toBe($originalJson);
});
