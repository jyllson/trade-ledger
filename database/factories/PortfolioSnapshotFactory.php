<?php

namespace Database\Factories;

use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioSnapshot>
 */
class PortfolioSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trader_id' => Trader::factory(),
            'captured_at' => now(),
            'last_confirmed_at' => now(),
            'cash_weight_ppb' => 0,
            'invested_weight_ppb' => 1_000_000_000,
            'position_count' => 0,
            'social_trades_count' => 0,
            'source_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
