<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition as LivePortfolioPosition;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;

/**
 * Stored snapshot (D-038) → the same CopyCoverageCalculator input the live
 * path builds (docs/DECISIONS.md D-042). The snapshot is restored into the
 * eToro domain LivePortfolio and handed to the existing
 * LivePortfolioCoverageAdapter, so live and stored coverage share one
 * translation rule: every position, in payload order (`position_index`),
 * unchanged — including zero/negative weights and duplicate ids — and
 * socialTrades as unmodeled entries.
 *
 * The restored portfolio carries what coverage and the simulator read
 * (ids, exact ppb weights, cash, socialTrades count) plus the exactly
 * stored open time, side, leverage and trailing flag. Take-profit /
 * stop-loss rates stay null: they are stored as decimal strings and are not
 * converted back to floats.
 */
final class StoredPortfolioCoverageAdapter
{
    public function __construct(private readonly LivePortfolioCoverageAdapter $liveAdapter) {}

    public function toLivePortfolio(PortfolioSnapshot $snapshot): LivePortfolio
    {
        return new LivePortfolio(
            positions: array_values($snapshot->positions()->get()->map(
                fn (PortfolioPosition $position): LivePortfolioPosition => new LivePortfolioPosition(
                    positionId: $position->external_position_id,
                    instrumentId: $position->external_instrument_id,
                    weight: Percentage::fromPartsPerBillion($position->weight_ppb),
                    openedAt: $position->opened_at?->toDateTimeImmutable(),
                    isBuy: $position->is_buy,
                    leverage: $position->leverage,
                    trailingStopLoss: $position->trailing_stop_loss,
                ),
            )->all()),
            socialTradesCount: $snapshot->social_trades_count,
            cashWeight: $snapshot->cash_weight_ppb === null ? null : Percentage::fromPartsPerBillion($snapshot->cash_weight_ppb),
        );
    }

    public function toCopyCoverageRequest(LivePortfolio $portfolio, Money $copyAmount, Money $minimumPositionAmount): CopyCoverageRequest
    {
        return $this->liveAdapter->toCopyCoverageRequest($portfolio, $copyAmount, $minimumPositionAmount);
    }
}
