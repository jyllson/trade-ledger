<?php

namespace Database\Factories;

use App\Application\AnalysisProfiles\AnalysisProfileSettings;
use App\Models\AnalysisProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A non-default profile with the built-in default values (D-048). Use the
 * `asDefault` state only on an empty table — at most one default exists.
 *
 * @extends Factory<AnalysisProfile>
 */
class AnalysisProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'budget_cents' => AnalysisProfileSettings::DEFAULT_BUDGET_CENTS,
            'target_coverage_ppb' => AnalysisProfileSettings::DEFAULT_TARGET_COVERAGE_PPB,
            'maximum_drawdown_ppb' => null,
            'maximum_risk_score' => null,
            'maximum_single_position_ppb' => null,
            'minimum_history_months' => null,
            'minimum_positive_months_ppb' => null,
            'maximum_allocation_per_trader_ppb' => null,
        ];
    }

    public function asDefault(): static
    {
        return $this->state(['is_default' => true]);
    }
}
