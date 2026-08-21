<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportRunFailureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single, row-level, permanent audit record of one controlled ranking
 * identity conflict — the "failed rows are visible" evidence behind a
 * per-page `rankings` ImportRun's count-only, sanitized `error_summary`.
 * App\Application\Imports\ImportRankingPage is the only writer: it records
 * each row against the per-page/standalone `rankings` ImportRun it is
 * finalizing, and the live discovery pipeline
 * (App\Application\Imports\DiscoverEtoroTraders) never duplicates these
 * rows onto its `rankings_discovery` aggregate run, which only ever
 * aggregates counts from its child runs (see
 * App\Models\ImportRun::childRuns()). This is a fact about the current
 * writer's behavior, not a database or model-level constraint — the
 * `import_run_id` foreign key itself does not restrict which ImportRun
 * `type` it may reference.
 *
 * @property int $id
 * @property int $import_run_id
 * @property int $row_number
 * @property string $external_cid
 * @property string $username
 * @property ImportRunFailureReason $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['import_run_id', 'row_number', 'external_cid', 'username', 'reason'])]
class ImportRunFailure extends Model
{
    /** @use HasFactory<ImportRunFailureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'import_run_id' => 'integer',
            'row_number' => 'integer',
            'reason' => ImportRunFailureReason::class,
        ];
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
