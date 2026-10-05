<?php

namespace Database\Factories;

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Models\PerformancePoint;
use App\Models\Trader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformancePoint>
 */
class PerformancePointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trader_id' => Trader::factory(),
            'granularity' => ReturnPeriodGranularity::Monthly,
            'period_start' => '2020-01-01',
            'gain_ppb' => fake()->numberBetween(-100_000_000, 100_000_000),
            'source' => PerformancePoint::SOURCE_ETORO_V2_GAIN,
            'synced_at' => now(),
        ];
    }
}
