<?php

namespace Database\Factories;

use App\Models\AccountPosition;
use App\Models\AccountSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountPosition>
 */
class AccountPositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_snapshot_id' => AccountSnapshot::factory(),
            'account_mirror_id' => null,
            'position_index' => 0,
            'external_position_id' => (string) fake()->unique()->numberBetween(1_000, 999_999),
            'external_mirror_id' => null,
            'external_parent_position_id' => null,
            'instrument_id' => null,
            'external_instrument_id' => (string) fake()->numberBetween(1, 9_999),
            'is_buy' => true,
            'amount_cents' => 50_000,
            'initial_amount_cents' => 50_000,
            'units' => '1.5000000000',
            'open_rate' => '123.4500000000',
            'opened_at' => now()->subDay(),
            'leverage' => 1,
            'pnl_cents' => 1_234,
        ];
    }
}
