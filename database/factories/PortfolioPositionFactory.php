<?php

namespace Database\Factories;

use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioPosition>
 */
class PortfolioPositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_snapshot_id' => PortfolioSnapshot::factory(),
            'position_index' => fake()->unique()->numberBetween(0, 100_000),
            'external_position_id' => (string) fake()->unique()->numberBetween(1, 99_999_999),
            'instrument_id' => null,
            'external_instrument_id' => (string) fake()->numberBetween(1, 9_999_999),
            'weight_ppb' => fake()->numberBetween(1_000_000, 100_000_000),
            'opened_at' => now()->subDays(30),
            'is_buy' => true,
            'leverage' => 1,
            'take_profit_rate' => null,
            'stop_loss_rate' => null,
            'trailing_stop_loss' => false,
        ];
    }
}
