<?php

namespace Database\Factories;

use App\Models\AccountMirror;
use App\Models\AccountSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountMirror>
 */
class AccountMirrorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_snapshot_id' => AccountSnapshot::factory(),
            'mirror_index' => 0,
            'external_mirror_id' => (string) fake()->unique()->numberBetween(1_000, 999_999),
            'parent_cid' => (string) fake()->unique()->numberBetween(1_000, 999_999),
            'parent_username' => fake()->userName(),
            'initial_investment_cents' => 100_000,
            'deposit_summary_cents' => 0,
            'withdrawal_summary_cents' => 0,
            'available_amount_cents' => 10_000,
            'closed_positions_net_profit_cents' => 0,
            'invested_cents' => 90_000,
            'unrealized_pnl_cents' => 0,
            'position_count' => 0,
            'started_at' => now()->subMonth(),
            'is_paused' => false,
            'pending_for_closure' => false,
            'status_id' => 0,
        ];
    }
}
