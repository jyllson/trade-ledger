<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
#[Fillable(['source', 'type', 'status', 'metadata', 'request_count', 'success_count', 'failure_count', 'started_at', 'finished_at', 'error_summary'])]
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
            'status' => ImportRunStatus::class,
            'metadata' => 'array',
            'request_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
