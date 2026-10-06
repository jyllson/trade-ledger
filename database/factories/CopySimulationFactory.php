<?php

namespace Database\Factories;

use App\Models\CopySimulation;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Inert row shape only — the result is not derived from the snapshot. Use
 * App\Application\Traders\SimulateCopyAmount for a real simulation.
 *
 * @extends Factory<CopySimulation>
 */
class CopySimulationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trader_id' => Trader::factory(),
            'portfolio_snapshot_id' => fn (array $attributes): int => PortfolioSnapshot::factory()->create(['trader_id' => $attributes['trader_id']])->id,
            'copy_amount_cents' => 50_000,
            'minimum_position_amount_cents' => 100,
            'platform_minimum_copy_amount_cents' => 20_000,
            'target_coverage_ppb' => null,
            'eligible_positions_count' => 0,
            'skipped_positions_count' => 0,
            'eligible_weight_ppb' => 0,
            'skipped_weight_ppb' => 0,
            'cash_weight_ppb' => 0,
            'minimum_target_amount_cents' => null,
            'methodology_version' => 'copy-simulation-v1',
            'result' => [],
            'calculated_at' => now(),
        ];
    }
}
