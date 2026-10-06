<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InstrumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An eToro instrument referenced by a stored portfolio position. Created as
 * a bare external id when first seen; metadata is best-effort enrichment
 * (docs/DECISIONS.md D-037/D-038).
 *
 * @property int $id
 * @property string $external_instrument_id
 * @property string|null $symbol
 * @property string|null $name
 * @property int|null $instrument_type_id
 * @property string|null $asset_class
 * @property int|null $exchange_id
 * @property int|null $stocks_industry_id
 * @property Carbon|null $metadata_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'external_instrument_id',
    'symbol',
    'name',
    'instrument_type_id',
    'asset_class',
    'exchange_id',
    'stocks_industry_id',
    'metadata_synced_at',
])]
class Instrument extends Model
{
    /** @use HasFactory<InstrumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instrument_type_id' => 'integer',
            'exchange_id' => 'integer',
            'stocks_industry_id' => 'integer',
            'metadata_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PortfolioPosition, $this>
     */
    public function portfolioPositions(): HasMany
    {
        return $this->hasMany(PortfolioPosition::class);
    }
}
