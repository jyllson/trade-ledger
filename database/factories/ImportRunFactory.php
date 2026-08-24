<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_import_run_id' => null,
            'retry_of_import_run_id' => null,
            'source' => 'etoro',
            'type' => 'rankings',
            'status' => ImportRunStatus::Pending,
            'metadata' => null,
            'request_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
            'error_summary' => null,
        ];
    }
}
