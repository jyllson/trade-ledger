<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccountMirrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One copy (eToro "mirror") in an account snapshot (docs/DECISIONS.md
 * D-051). Money in integer USD cents.
 *
 * @property int $id
 * @property int $account_snapshot_id
 * @property int $mirror_index
 * @property string $external_mirror_id
 * @property string $parent_cid
 * @property string|null $parent_username
 * @property int|null $initial_investment_cents
 * @property int|null $deposit_summary_cents
 * @property int|null $withdrawal_summary_cents
 * @property int|null $available_amount_cents
 * @property int|null $closed_positions_net_profit_cents
 * @property int|null $invested_cents
 * @property int|null $unrealized_pnl_cents
 * @property int|null $position_count
 * @property Carbon|null $started_at
 * @property bool|null $is_paused
 * @property bool|null $pending_for_closure
 * @property int|null $status_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_snapshot_id',
    'mirror_index',
    'external_mirror_id',
    'parent_cid',
    'parent_username',
    'initial_investment_cents',
    'deposit_summary_cents',
    'withdrawal_summary_cents',
    'available_amount_cents',
    'closed_positions_net_profit_cents',
    'invested_cents',
    'unrealized_pnl_cents',
    'position_count',
    'started_at',
    'is_paused',
    'pending_for_closure',
    'status_id',
])]
class AccountMirror extends Model
{
    /** @use HasFactory<AccountMirrorFactory> */
    use HasFactory;

    /**
     * Documented `mirrorStatusID` values (OpenAPI v1.387.0).
     */
    public const STATUS_LABELS = [
        0 => 'Active',
        1 => 'Paused',
        2 => 'Pending closure',
        3 => 'In alignment process',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_snapshot_id' => 'integer',
            'mirror_index' => 'integer',
            'initial_investment_cents' => 'integer',
            'deposit_summary_cents' => 'integer',
            'withdrawal_summary_cents' => 'integer',
            'available_amount_cents' => 'integer',
            'closed_positions_net_profit_cents' => 'integer',
            'invested_cents' => 'integer',
            'unrealized_pnl_cents' => 'integer',
            'position_count' => 'integer',
            'started_at' => 'datetime',
            'is_paused' => 'boolean',
            'pending_for_closure' => 'boolean',
            'status_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AccountSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AccountSnapshot::class, 'account_snapshot_id');
    }

    /**
     * The copied trader, when stored (matched by CID, D-051).
     *
     * @return BelongsTo<Trader, $this>
     */
    public function trader(): BelongsTo
    {
        return $this->belongsTo(Trader::class, 'parent_cid', 'external_cid');
    }

    /**
     * @return HasMany<AccountPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(AccountPosition::class)->orderBy('position_index');
    }

    public function statusLabel(): ?string
    {
        return $this->status_id === null ? null : (self::STATUS_LABELS[$this->status_id] ?? 'Status '.$this->status_id);
    }
}
