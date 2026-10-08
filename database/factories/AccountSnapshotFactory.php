<?php

namespace Database\Factories;

use App\Etoro\EtoroEnvironment;
use App\Models\AccountSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSnapshot>
 */
class AccountSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => EtoroEnvironment::Demo,
            'captured_at' => now(),
            'last_confirmed_at' => now(),
            'credit_cents' => 10_000_000,
            'unrealized_pnl_cents' => 0,
            'bonus_credit_cents' => 0,
            'account_currency_id' => 1,
            'invested_cents' => 0,
            'equity_cents' => 10_000_000,
            'equity_unavailable_reason' => null,
            'position_count' => 0,
            'mirror_count' => 0,
            'mirror_position_count' => 0,
            'pending_order_count' => 0,
            'source_hash' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
