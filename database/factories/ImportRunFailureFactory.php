<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\ImportRunFailure;
use App\Models\ImportRunFailureReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRunFailure>
 */
class ImportRunFailureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_run_id' => ImportRun::factory(),
            'row_number' => 1,
            'external_cid' => 'factory-cid',
            'username' => 'factory-username',
            'reason' => ImportRunFailureReason::IdentityConflictWithinPage,
        ];
    }
}
