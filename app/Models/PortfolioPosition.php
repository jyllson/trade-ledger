<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PortfolioPositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One position of a stored portfolio snapshot (docs/DECISIONS.md D-038).
 * Rates are exact decimal strings (10 decimal places), never floats.
 *
 * @property int $id
 * @property int $portfolio_snapshot_id
 * @property int $position_index
 * @property string $external_position_id
 * @property int|null $instrument_id
 * @property string $external_instrument_id
 * @property int $weight_ppb
 * @property Carbon|null $opened_at
 * @property bool|null $is_buy
 * @property int|null $leverage
 * @property string|null $take_profit_rate
 * @property string|null $stop_loss_rate
 * @property bool|null $trailing_stop_loss
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'portfolio_snapshot_id',
    'position_index',
    'external_position_id',
    'instrument_id',
    'external_instrument_id',
    'weight_ppb',
    'opened_at',
    'is_buy',
    'leverage',
    'take_profit_rate',
    'stop_loss_rate',
    'trailing_stop_loss',
])]
class PortfolioPosition extends Model
{
    /** @use HasFactory<PortfolioPositionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_snapshot_id' => 'integer',
            'position_index' => 'integer',
            'instrument_id' => 'integer',
            'weight_ppb' => 'integer',
            'opened_at' => 'datetime',
            'is_buy' => 'boolean',
            'leverage' => 'integer',
            'take_profit_rate' => 'decimal:10',
            'stop_loss_rate' => 'decimal:10',
            'trailing_stop_loss' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PortfolioSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PortfolioSnapshot::class, 'portfolio_snapshot_id');
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
