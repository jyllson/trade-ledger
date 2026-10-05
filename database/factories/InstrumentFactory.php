<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    /**
     * A bare instrument as first seen in a portfolio, before enrichment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_instrument_id' => (string) fake()->unique()->numberBetween(1, 9_999_999),
        ];
    }

    public function enriched(): static
    {
        return $this->state(fn (): array => [
            'symbol' => strtoupper(fake()->lexify('????')),
            'name' => fake()->company(),
            'instrument_type_id' => 5,
            'asset_class' => 'Stocks',
            'exchange_id' => fake()->numberBetween(1, 50),
            'stocks_industry_id' => null,
            'metadata_synced_at' => now(),
        ]);
    }
}
