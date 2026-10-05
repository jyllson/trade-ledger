<?php

declare(strict_types=1);

namespace App\Models;

use App\Analytics\Data\ReturnPeriodGranularity;
use Database\Factories\PerformancePointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored period return of a trader (docs/DECISIONS.md D-034).
 *
 * @property int $id
 * @property int $trader_id
 * @property ReturnPeriodGranularity $granularity
 * @property string $period_start Y-m-d (UTC); deliberately not a date cast so every write path stores the same plain date string
 * @property int $gain_ppb
 * @property string $source
 * @property Carbon $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['trader_id', 'granularity', 'period_start', 'gain_ppb', 'source', 'synced_at'])]
class PerformancePoint extends Model
{
    /** @use HasFactory<PerformancePointFactory> */
    use HasFactory;

    public const SOURCE_ETORO_V2_GAIN = 'etoro_v2_gain';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trader_id' => 'integer',
            'granularity' => ReturnPeriodGranularity::class,
            'gain_ppb' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Trader, $this>
     */
    public function trader(): BelongsTo
    {
        return $this->belongsTo(Trader::class);
    }
}
