<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PortfolioSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One distinct observed live portfolio of a trader (docs/DECISIONS.md D-038).
 * Weights are exact fractions in parts per billion.
 *
 * @property int $id
 * @property int $trader_id
 * @property Carbon $captured_at
 * @property Carbon $last_confirmed_at
 * @property int|null $cash_weight_ppb
 * @property int $invested_weight_ppb
 * @property int $position_count
 * @property int $social_trades_count
 * @property string $source_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'trader_id',
    'captured_at',
    'last_confirmed_at',
    'cash_weight_ppb',
    'invested_weight_ppb',
    'position_count',
    'social_trades_count',
    'source_hash',
])]
class PortfolioSnapshot extends Model
{
    /** @use HasFactory<PortfolioSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trader_id' => 'integer',
            'captured_at' => 'datetime',
            'last_confirmed_at' => 'datetime',
            'cash_weight_ppb' => 'integer',
            'invested_weight_ppb' => 'integer',
            'position_count' => 'integer',
            'social_trades_count' => 'integer',
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
     * Positions in their original payload order.
     *
     * @return HasMany<PortfolioPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(PortfolioPosition::class)->orderBy('position_index');
    }
}
