<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CopySimulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored copy-amount simulation over a stored portfolio snapshot
 * (docs/DECISIONS.md D-042). Money in integer cents, weights in ppb; the
 * full, versioned explanation lives in `result`.
 *
 * @property int $id
 * @property int $trader_id
 * @property int $portfolio_snapshot_id
 * @property int $copy_amount_cents
 * @property int $minimum_position_amount_cents
 * @property int $platform_minimum_copy_amount_cents
 * @property int|null $target_coverage_ppb
 * @property int $eligible_positions_count
 * @property int $skipped_positions_count
 * @property int $eligible_weight_ppb
 * @property int $skipped_weight_ppb
 * @property int|null $cash_weight_ppb
 * @property int|null $minimum_target_amount_cents
 * @property string $methodology_version
 * @property array<string, mixed> $result
 * @property Carbon $calculated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'trader_id',
    'portfolio_snapshot_id',
    'copy_amount_cents',
    'minimum_position_amount_cents',
    'platform_minimum_copy_amount_cents',
    'target_coverage_ppb',
    'eligible_positions_count',
    'skipped_positions_count',
    'eligible_weight_ppb',
    'skipped_weight_ppb',
    'cash_weight_ppb',
    'minimum_target_amount_cents',
    'methodology_version',
    'result',
    'calculated_at',
])]
class CopySimulation extends Model
{
    /** @use HasFactory<CopySimulationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trader_id' => 'integer',
            'portfolio_snapshot_id' => 'integer',
            'copy_amount_cents' => 'integer',
            'minimum_position_amount_cents' => 'integer',
            'platform_minimum_copy_amount_cents' => 'integer',
            'target_coverage_ppb' => 'integer',
            'eligible_positions_count' => 'integer',
            'skipped_positions_count' => 'integer',
            'eligible_weight_ppb' => 'integer',
            'skipped_weight_ppb' => 'integer',
            'cash_weight_ppb' => 'integer',
            'minimum_target_amount_cents' => 'integer',
            'result' => 'array',
            'calculated_at' => 'datetime',
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
     * @return BelongsTo<PortfolioSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PortfolioSnapshot::class, 'portfolio_snapshot_id');
    }
}
