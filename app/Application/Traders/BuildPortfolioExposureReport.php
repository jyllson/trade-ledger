<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Calculators\ConcentrationCalculator;
use App\Analytics\Calculators\LeverageExposureCalculator;
use App\Analytics\Data\PortfolioHolding;
use App\Analytics\Data\PortfolioHoldings;
use App\Analytics\ValueObjects\Percentage;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;

/**
 * Builds concentration and leverage exposure from a STORED portfolio
 * snapshot only — never calls the eToro API (D-031/D-041).
 *
 * Mapping (D-041):
 * - instrument key = `external_instrument_id` (always present);
 * - asset class = `instruments.asset_class`; null when the instrument is
 *   not enriched/unresolved ⇒ the explicit "unknown" group;
 * - sector is NOT passed: `stocks_industry_id` has no catalog and is not a
 *   verified sector, so the sector dimension is reported as unavailable;
 * - leverage as stored (null ⇒ unknown, never 1x);
 * - cash = `cash_weight_ppb` (null ⇒ unknown).
 */
final class BuildPortfolioExposureReport
{
    public function __construct(
        private readonly ConcentrationCalculator $concentrationCalculator,
        private readonly LeverageExposureCalculator $leverageCalculator,
    ) {}

    public function handle(PortfolioSnapshot $snapshot): PortfolioExposureReport
    {
        /** @var list<PortfolioPosition> $positions */
        $positions = array_values($snapshot->positions()->with('instrument')->get()->all());

        return $this->fromPositions($snapshot, $positions);
    }

    /**
     * Same as handle() for positions the caller already loaded (snapshot
     * order, `instrument` eager loaded), so one snapshot is read once.
     *
     * @param  list<PortfolioPosition>  $positions
     */
    public function fromPositions(PortfolioSnapshot $snapshot, array $positions): PortfolioExposureReport
    {
        $holdings = new PortfolioHoldings(
            holdings: array_map(fn (PortfolioPosition $position): PortfolioHolding => $this->holding($position), $positions),
            cashWeight: $snapshot->cash_weight_ppb === null ? null : Percentage::fromPartsPerBillion($snapshot->cash_weight_ppb),
            sectorClassificationAvailable: false,
        );

        return new PortfolioExposureReport(
            portfolioSnapshotId: $snapshot->id,
            capturedAt: $snapshot->captured_at->toDateTimeImmutable(),
            concentration: $this->concentrationCalculator->calculate($holdings),
            leverage: $this->leverageCalculator->calculate($holdings),
        );
    }

    /**
     * Report for the trader's most recently captured snapshot, or null when
     * none is stored.
     */
    public function latestForTrader(Trader $trader): ?PortfolioExposureReport
    {
        $snapshot = PortfolioSnapshot::query()
            ->where('trader_id', $trader->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        return $snapshot === null ? null : $this->handle($snapshot);
    }

    private function holding(PortfolioPosition $position): PortfolioHolding
    {
        $assetClass = $position->instrument?->asset_class;

        return new PortfolioHolding(
            positionId: $position->external_position_id,
            instrumentKey: $position->external_instrument_id,
            weight: Percentage::fromPartsPerBillion($position->weight_ppb),
            assetClass: $assetClass === null || trim($assetClass) === '' ? null : $assetClass,
            sector: null,
            leverage: $position->leverage,
        );
    }
}
