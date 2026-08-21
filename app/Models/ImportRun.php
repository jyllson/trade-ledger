<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_import_run_id
 * @property int|null $retry_of_import_run_id
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
#[Fillable(['parent_import_run_id', 'retry_of_import_run_id', 'source', 'type', 'status', 'metadata', 'request_count', 'success_count', 'failure_count', 'started_at', 'finished_at', 'error_summary'])]
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
            'retry_of_import_run_id' => 'integer',
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

    /**
     * The run this one is a manual retry of — the run it immediately
     * followed in a retry chain, never the chain's root. Null for every
     * ordinary (non-retry) run.
     *
     * @return BelongsTo<ImportRun, $this>
     */
    public function retryOfRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_import_run_id');
    }

    /**
     * Runs created as a manual retry of THIS run — the immediate next
     * attempt(s) in a retry chain, not any further descendant.
     *
     * @return HasMany<ImportRun, $this>
     */
    public function retryAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_import_run_id');
    }

    /**
     * Row-level controlled identity conflicts rejected under THIS run.
     * App\Application\Imports\ImportRankingPage is the only writer, and it
     * only ever records these against the per-page `rankings` (or
     * fixture-only single-page) run it is finalizing — it never runs
     * against a `rankings_discovery` aggregate run at all, so an aggregate
     * accumulates none here directly. Neither the database schema nor this
     * model enforces that as a constraint; it is a fact about the current
     * writer, not a guarantee. See childFailures() to reach a discovery
     * aggregate's direct child runs' failures.
     *
     * @return HasMany<ImportRunFailure, $this>
     */
    public function failures(): HasMany
    {
        return $this->hasMany(ImportRunFailure::class);
    }

    /**
     * Narrow, typed convenience for a `rankings_discovery` aggregate run:
     * every ImportRunFailure belonging to one of its DIRECT childRuns(), in
     * one query, without duplicating failure rows onto the aggregate
     * itself. This is exactly one level deep — it does not recurse into
     * grandchildren or any deeper descendant, which matches the current
     * discovery pipeline's shape (an aggregate's children are always
     * per-page `rankings` runs, which have no children of their own). A
     * plain self-referencing hasManyThrough — no collation-sensitive string
     * comparison is involved (only integer foreign-key joins), so this
     * works identically on SQLite and MySQL.
     *
     * @return HasManyThrough<ImportRunFailure, ImportRun, $this>
     */
    public function childFailures(): HasManyThrough
    {
        return $this->hasManyThrough(
            ImportRunFailure::class,
            self::class,
            'parent_import_run_id',
            'import_run_id',
            'id',
            'id',
        );
    }
}
