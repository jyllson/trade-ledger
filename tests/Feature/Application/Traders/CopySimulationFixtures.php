<?php

use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;

/**
 * Hand-computed stored snapshot shared by the copy simulator tests
 * (docs/DECISIONS.md D-042). M = $1 = 100 cents, platform minimum $200.
 * Breakpoint bpᵢ = ceil(100 × 10⁹ / wᵢ) cents.
 *
 *   #  id     weight        ppb        bp (cents)
 *   0  pos-a  50%     500_000_000         200   ($2.00)
 *   1  pos-b  30%     300_000_000         334   (333.33… → $3.34)
 *   2  pos-c  10%     100_000_000       1_000   ($10.00)
 *   3  pos-d   6%      60_000_000       1_667   (1666.67 → $16.67)
 *   4  pos-e   2%      20_000_000       5_000   ($50.00)
 *   5  pos-f   0.45%    4_500_000      22_223   (22222.2 → $222.23)
 *   6  pos-g   0.4%     4_000_000      25_000   ($250.00)
 *   7  pos-h   0.3%     3_000_000      33_334   (33333.3 → $333.34)
 *   8  pos-i   0.1%     1_000_000     100_000   ($1,000.00)
 *   9  pos-z   0%               0          —    zero weight
 *
 * Σ positions = 99.25% (992_500_000) = positive weight P; cash 0.75%
 * (7_500_000) ⇒ unknown weight 0, no data-quality warning.
 */
function copySimulationSnapshot(?Trader $trader = null, array $snapshotAttributes = []): PortfolioSnapshot
{
    return copySimulationStoredSnapshot([
        ['pos-a', '1001', 500_000_000],
        ['pos-b', '1002', 300_000_000],
        ['pos-c', '1003', 100_000_000],
        ['pos-d', '1004', 60_000_000],
        ['pos-e', '1005', 20_000_000],
        ['pos-f', '1006', 4_500_000],
        ['pos-g', '1007', 4_000_000],
        ['pos-h', '1008', 3_000_000],
        ['pos-i', '1009', 1_000_000],
        ['pos-z', '1010', 0],
    ], [
        'cash_weight_ppb' => 7_500_000,
        'captured_at' => '2026-10-05 10:00:00',
        ...$snapshotAttributes,
    ], $trader);
}

/**
 * @param  list<array{0: string, 1: string, 2: int}>  $positions  [position id, instrument id, weight ppb]
 * @param  array<string, mixed>  $snapshotAttributes
 */
function copySimulationStoredSnapshot(array $positions, array $snapshotAttributes = [], ?Trader $trader = null): PortfolioSnapshot
{
    $snapshot = PortfolioSnapshot::factory()->for($trader ?? Trader::factory())->create([
        'invested_weight_ppb' => array_sum(array_column($positions, 2)),
        'position_count' => count($positions),
        ...$snapshotAttributes,
    ]);

    foreach ($positions as $index => [$positionId, $instrumentId, $weight]) {
        PortfolioPosition::factory()->for($snapshot, 'snapshot')->create([
            'position_index' => $index,
            'external_position_id' => $positionId,
            'external_instrument_id' => $instrumentId,
            'weight_ppb' => $weight,
        ]);
    }

    return $snapshot;
}
