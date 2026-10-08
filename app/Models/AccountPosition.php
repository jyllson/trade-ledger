<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccountPositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One open position in an account snapshot (docs/DECISIONS.md D-051).
 * Money in integer USD cents; units and rates are exact decimal strings.
 *
 * @property int $id
 * @property int $account_snapshot_id
 * @property int|null $account_mirror_id
 * @property int $position_index
 * @property string $external_position_id
 * @property string|null $external_mirror_id
 * @property string|null $external_parent_position_id
 * @property int|null $instrument_id
 * @property string $external_instrument_id
 * @property bool $is_buy
 * @property int $amount_cents
 * @property int|null $initial_amount_cents
 * @property numeric-string|null $units
 * @property numeric-string|null $open_rate
 * @property Carbon|null $opened_at
 * @property int|null $leverage
 * @property int|null $pnl_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_snapshot_id',
    'account_mirror_id',
    'position_index',
    'external_position_id',
    'external_mirror_id',
    'external_parent_position_id',
    'instrument_id',
    'external_instrument_id',
    'is_buy',
    'amount_cents',
    'initial_amount_cents',
    'units',
    'open_rate',
    'opened_at',
    'leverage',
    'pnl_cents',
])]
class AccountPosition extends Model
{
    /** @use HasFactory<AccountPositionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_snapshot_id' => 'integer',
            'account_mirror_id' => 'integer',
            'position_index' => 'integer',
            'instrument_id' => 'integer',
            'is_buy' => 'boolean',
            'amount_cents' => 'integer',
            'initial_amount_cents' => 'integer',
            'units' => 'decimal:10',
            'open_rate' => 'decimal:10',
            'opened_at' => 'datetime',
            'leverage' => 'integer',
            'pnl_cents' => 'integer',
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
     * @return BelongsTo<AccountMirror, $this>
     */
    public function mirror(): BelongsTo
    {
        return $this->belongsTo(AccountMirror::class, 'account_mirror_id');
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
