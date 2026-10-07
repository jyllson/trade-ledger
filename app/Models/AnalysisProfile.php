<?php

declare(strict_types=1);

namespace App\Models;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\AnalysisProfiles\AnalysisProfileCriteria;
use App\Application\AnalysisProfiles\DefaultAnalysisProfileCannotBeDeleted;
use Database\Factories\AnalysisProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The user's configurable restrictions (PROJECT.md §11, docs/DECISIONS.md
 * D-048). Money in integer cents, fractions in ppb; a null restrictive
 * criterion is not applied.
 *
 * `is_default` is deliberately not fillable: the default is stored by a
 * migration and moved only by MakeAnalysisProfileDefault (at most one is
 * also enforced by a unique index over the generated `default_marker`);
 * it cannot be deleted — deletes go through DeleteAnalysisProfiles
 * (both in App\Application\AnalysisProfiles).
 *
 * @property int $id
 * @property string $name
 * @property int $budget_cents
 * @property int $target_coverage_ppb
 * @property int|null $maximum_drawdown_ppb
 * @property int|null $maximum_risk_score
 * @property int|null $maximum_single_position_ppb
 * @property int|null $minimum_history_months
 * @property int|null $minimum_positive_months_ppb
 * @property int|null $maximum_allocation_per_trader_ppb
 * @property bool $is_default
 * @property int|null $default_marker
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'budget_cents',
    'target_coverage_ppb',
    'maximum_drawdown_ppb',
    'maximum_risk_score',
    'maximum_single_position_ppb',
    'minimum_history_months',
    'minimum_positive_months_ppb',
    'maximum_allocation_per_trader_ppb',
])]
class AnalysisProfile extends Model
{
    /** @use HasFactory<AnalysisProfileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Last line of defence for a direct `delete()`: reads the stored flag,
        // not the possibly stale loaded one. The race-free path is
        // DeleteAnalysisProfiles (locked, checked right before the DELETE).
        static::deleting(function (AnalysisProfile $profile): void {
            if (static::query()->whereKey($profile->getKey())->where('is_default', true)->exists()) {
                throw new DefaultAnalysisProfileCannotBeDeleted;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_cents' => 'integer',
            'target_coverage_ppb' => 'integer',
            'maximum_drawdown_ppb' => 'integer',
            'maximum_risk_score' => 'integer',
            'maximum_single_position_ppb' => 'integer',
            'minimum_history_months' => 'integer',
            'minimum_positive_months_ppb' => 'integer',
            'maximum_allocation_per_trader_ppb' => 'integer',
            'is_default' => 'boolean',
            'default_marker' => 'integer',
        ];
    }

    public function toCriteria(): AnalysisProfileCriteria
    {
        return new AnalysisProfileCriteria(
            profileId: $this->id,
            name: $this->name,
            budget: Money::fromCents($this->budget_cents),
            targetCoverage: Percentage::fromPartsPerBillion($this->target_coverage_ppb),
            maximumDrawdown: self::fraction($this->maximum_drawdown_ppb),
            maximumRiskScore: $this->maximum_risk_score,
            maximumSinglePosition: self::fraction($this->maximum_single_position_ppb),
            minimumHistoryMonths: $this->minimum_history_months,
            minimumPositiveMonths: self::fraction($this->minimum_positive_months_ppb),
            maximumAllocationPerTrader: self::fraction($this->maximum_allocation_per_trader_ppb),
        );
    }

    private static function fraction(?int $ppb): ?Percentage
    {
        return $ppb === null ? null : Percentage::fromPartsPerBillion($ppb);
    }
}
