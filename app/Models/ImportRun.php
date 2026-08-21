<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_import_run_id
 * @property string $source
 * @property string $type
 * @property ImportRunStatus $status
 * @property array<string, mixed>|null $metadata
 * @property int $request_count
 * @property int $success_count
 * @property int $failure_count
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_import_run_id', 'source', 'type', 'status', 'metadata', 'request_count', 'success_count', 'failure_count', 'started_at', 'finished_at', 'error_summary'])]
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_import_run_id' => 'integer',
            'status' => ImportRunStatus::class,
            'metadata' => 'array',
            'request_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The `rankings_discovery` aggregate run this per-page `rankings` run
     * was created under, when applicable — null for single-page/fixture
     * callers that pass no parent.
     *
     * @return BelongsTo<ImportRun, $this>
     */
    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_import_run_id');
    }

    /**
     * The per-page `rankings` runs a `rankings_discovery` aggregate run
     * spawned.
     *
     * @return HasMany<ImportRun, $this>
     */
    public function childRuns(): HasMany
    {
        return $this->hasMany(self::class, 'parent_import_run_id');
    }
}
