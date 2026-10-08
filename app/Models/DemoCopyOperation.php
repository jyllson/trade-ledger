<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DemoCopyOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One audited demo copy-trading attempt (docs/DECISIONS.md D-050). Written
 * only by the App\Application\DemoCopy use cases; `response` holds an
 * allow-listed, sanitized subset of the eToro body, never headers or keys.
 *
 * @property int $id
 * @property int|null $trader_id
 * @property int|null $user_id
 * @property int|null $parent_operation_id
 * @property DemoCopyOperationType $type
 * @property DemoCopyOperationStatus $status
 * @property int|null $parent_cid
 * @property string|null $trader_username
 * @property int|null $amount_cents
 * @property string|null $reference_id
 * @property string|null $request_id
 * @property string|null $client_request_id
 * @property int|null $mirror_id
 * @property string|null $unregister_type
 * @property int|null $http_status
 * @property string|null $transport_outcome
 * @property string|null $error_code
 * @property string|null $reason
 * @property array<string, mixed>|null $response
 * @property Carbon|null $requested_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'trader_id',
    'user_id',
    'parent_operation_id',
    'type',
    'status',
    'parent_cid',
    'trader_username',
    'amount_cents',
    'reference_id',
    'request_id',
    'client_request_id',
    'mirror_id',
    'unregister_type',
    'http_status',
    'transport_outcome',
    'error_code',
    'reason',
    'response',
    'requested_at',
    'completed_at',
])]
class DemoCopyOperation extends Model
{
    /** @use HasFactory<DemoCopyOperationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trader_id' => 'integer',
            'user_id' => 'integer',
            'parent_operation_id' => 'integer',
            'type' => DemoCopyOperationType::class,
            'status' => DemoCopyOperationStatus::class,
            'parent_cid' => 'integer',
            'amount_cents' => 'integer',
            'mirror_id' => 'integer',
            'http_status' => 'integer',
            'response' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Trader, $this>
     */
    public function trader(): BelongsTo
    {
        return $this->belongsTo(Trader::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DemoCopyOperation, $this>
     */
    public function parentOperation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_operation_id');
    }

    /**
     * @return HasMany<DemoCopyOperation, $this>
     */
    public function childOperations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_operation_id');
    }
}
