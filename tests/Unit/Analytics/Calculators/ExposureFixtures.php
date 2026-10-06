<?php

declare(strict_types=1);

use App\Analytics\Data\PortfolioHolding;
use App\Analytics\Data\PortfolioHoldings;
use App\Analytics\ValueObjects\Percentage;

/**
 * Shorthand for a holding; weights in ppb of the WHOLE portfolio (1e9 = 100%).
 */
function exposureHolding(string $positionId, string $instrument, ?int $weightPpb, ?string $assetClass = null, ?int $leverage = 1, ?string $sector = null): PortfolioHolding
{
    return new PortfolioHolding(
        positionId: $positionId,
        instrumentKey: $instrument,
        weight: $weightPpb === null ? null : Percentage::fromPartsPerBillion($weightPpb),
        assetClass: $assetClass,
        sector: $sector,
        leverage: $leverage,
    );
}

/**
 * @param  list<PortfolioHolding>  $holdings
 */
function exposurePortfolio(array $holdings, ?int $cashPpb = 0, bool $sectors = false): PortfolioHoldings
{
    return new PortfolioHoldings($holdings, $cashPpb === null ? null : Percentage::fromPartsPerBillion($cashPpb), $sectors);
}

/**
 * Reference portfolio A (cash 10%, invested W = 90%):
 *
 *   p1  instrument 1001    Stocks      30%   1x
 *   p2  instrument 1001    Stocks      10%   2x   (same instrument as p1)
 *   p3  instrument 100000  Crypto      20%   1x
 *   p4  instrument 1       Currencies  15%   5x
 *   p5  instrument 100001  Crypto      15%   1x
 *
 * Invested-only weights in units of 1/18 (W = 90% = 18 × 5%):
 *   by instrument:  1001 = 8, 100000 = 4, 1 = 3, 100001 = 3
 *   by asset class: Stocks = 8, Crypto = 7, Currencies = 3
 */
function referenceExposurePortfolio(): PortfolioHoldings
{
    return exposurePortfolio([
        exposureHolding('p1', '1001', 300_000_000, 'Stocks', 1),
        exposureHolding('p2', '1001', 100_000_000, 'Stocks', 2),
        exposureHolding('p3', '100000', 200_000_000, 'Crypto', 1),
        exposureHolding('p4', '1', 150_000_000, 'Currencies', 5),
        exposureHolding('p5', '100001', 150_000_000, 'Crypto', 1),
    ], cashPpb: 100_000_000);
}

/**
 * Portfolio B with gaps (cash unknown):
 *
 *   a  instrument 10  Stocks   50%    1x
 *   b  instrument 20  (none)   30%    leverage unknown
 *   c  instrument 30  Stocks   20%    3x
 *   d  instrument 40  Crypto   weight unknown   2x
 *
 * Usable W = 100% (a + b + c); d is excluded from every weighted metric.
 */
function incompleteExposurePortfolio(): PortfolioHoldings
{
    return exposurePortfolio([
        exposureHolding('a', '10', 500_000_000, 'Stocks', 1),
        exposureHolding('b', '20', 300_000_000, null, null),
        exposureHolding('c', '30', 200_000_000, 'Stocks', 3),
        exposureHolding('d', '40', null, 'Crypto', 2),
    ], cashPpb: null);
}
