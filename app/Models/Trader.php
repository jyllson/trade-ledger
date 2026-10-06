<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TraderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_cid
 * @property string $username
 * @property string $ranking_type
 * @property string $ranking_sub_type
 * @property int $copiers_count
 * @property TraderStatus $status
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $profile_gcid
 * @property bool|null $profile_is_popular_investor
 * @property bool|null $profile_is_verified
 * @property int|null $profile_country_code
 * @property string|null $profile_language_iso_code
 * @property Carbon|null $profile_synced_at
 * @property Carbon|null $performance_synced_at
 * @property PerformanceVisibility|null $performance_visibility
 * @property Carbon|null $portfolio_synced_at
 * @property PerformanceVisibility|null $portfolio_visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'external_cid',
    'username',
    'ranking_type',
    'ranking_sub_type',
    'copiers_count',
    'status',
    'first_seen_at',
    'last_seen_at',
    'profile_gcid',
    'profile_is_popular_investor',
    'profile_is_verified',
    'profile_country_code',
    'profile_language_iso_code',
    'profile_synced_at',
    'performance_synced_at',
    'performance_visibility',
    'portfolio_synced_at',
    'portfolio_visibility',
])]
class Trader extends Model
{
    /** @use HasFactory<TraderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'copiers_count' => 'integer',
            'status' => TraderStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'profile_is_popular_investor' => 'boolean',
            'profile_is_verified' => 'boolean',
            'profile_country_code' => 'integer',
            'profile_synced_at' => 'datetime',
            'performance_synced_at' => 'datetime',
            'performance_visibility' => PerformanceVisibility::class,
            'portfolio_synced_at' => 'datetime',
            'portfolio_visibility' => PerformanceVisibility::class,
        ];
    }

    /**
     * @return HasMany<PerformancePoint, $this>
     */
    public function performancePoints(): HasMany
    {
        return $this->hasMany(PerformancePoint::class);
    }

    /**
     * @return HasMany<PortfolioSnapshot, $this>
     */
    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }

    /**
     * @return HasMany<CopySimulation, $this>
     */
    public function copySimulations(): HasMany
    {
        return $this->hasMany(CopySimulation::class);
    }

    /**
     * The most recently captured portfolio snapshot.
     *
     * @return HasOne<PortfolioSnapshot, $this>
     */
    public function latestPortfolioSnapshot(): HasOne
    {
        return $this->hasOne(PortfolioSnapshot::class)->ofMany(['captured_at' => 'max', 'id' => 'max']);
    }
}
