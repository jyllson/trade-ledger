<?php

namespace Database\Factories;

use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Inert audit row shape only — never sent anywhere. Use the
 * App\Application\DemoCopy use cases for real operations.
 *
 * @extends Factory<DemoCopyOperation>
 */
class DemoCopyOperationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trader_id' => Trader::factory(),
            'user_id' => null,
            'parent_operation_id' => null,
            'type' => DemoCopyOperationType::Start,
            'status' => DemoCopyOperationStatus::Succeeded,
            'parent_cid' => fake()->numberBetween(1_000, 9_999_999),
            'trader_username' => fake()->userName(),
            'amount_cents' => 50_000,
            'reference_id' => 'tl-'.Str::lower((string) Str::ulid()),
            'request_id' => (string) Str::uuid(),
            'mirror_id' => null,
            'requested_at' => now(),
            'completed_at' => now(),
        ];
    }
}
