<?php

namespace Database\Factories;

use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trader>
 */
class TraderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_cid' => fake()->unique()->numerify('#######'),
            'username' => fake()->unique()->userName(),
            'ranking_type' => 'trader',
            'ranking_sub_type' => 'pi-certified',
            'copiers_count' => fake()->numberBetween(0, 10_000),
            'status' => TraderStatus::Candidate,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
