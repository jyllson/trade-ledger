<?php

declare(strict_types=1);

namespace App\Models;

use App\Etoro\EtoroEnvironment;
use Database\Factories\AccountSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One distinct observed state of the authenticated eToro account
 * (docs/DECISIONS.md D-051). Money in integer USD cents.
 *
 * @property int $id
 * @property EtoroEnvironment $environment
 * @property Carbon $captured_at
 * @property Carbon $last_confirmed_at
 * @property int $credit_cents
 * @property int $unrealized_pnl_cents
 * @property int|null $bonus_credit_cents
 * @property int|null $account_currency_id
 * @property int|null $invested_cents
 * @property int|null $equity_cents
 * @property string|null $equity_unavailable_reason
 * @property int $position_count
 * @property int $mirror_count
 * @property int $mirror_position_count
 * @property int $pending_order_count
 * @property string $source_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'environment',
    'captured_at',
    'last_confirmed_at',
    'credit_cents',
    'unrealized_pnl_cents',
    'bonus_credit_cents',
    'account_currency_id',
    'invested_cents',
    'equity_cents',
    'equity_unavailable_reason',
    'position_count',
    'mirror_count',
    'mirror_position_count',
    'pending_order_count',
    'source_hash',
])]
class AccountSnapshot extends Model
{
    /** @use HasFactory<AccountSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => EtoroEnvironment::class,
            'captured_at' => 'datetime',
            'last_confirmed_at' => 'datetime',
            'credit_cents' => 'integer',
            'unrealized_pnl_cents' => 'integer',
            'bonus_credit_cents' => 'integer',
            'account_currency_id' => 'integer',
            'invested_cents' => 'integer',
            'equity_cents' => 'integer',
            'position_count' => 'integer',
            'mirror_count' => 'integer',
            'mirror_position_count' => 'integer',
            'pending_order_count' => 'integer',
        ];
    }

    /**
     * Every position of the snapshot (top-level first, then each mirror's),
     * in payload order.
     *
     * @return HasMany<AccountPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(AccountPosition::class)->orderBy('position_index');
    }

    /**
     * @return HasMany<AccountMirror, $this>
     */
    public function mirrors(): HasMany
    {
        return $this->hasMany(AccountMirror::class)->orderBy('mirror_index');
    }
}
