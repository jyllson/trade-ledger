<?php

use App\Models\AnalysisProfile;
use Illuminate\Database\Migrations\Migration;

function seedDefaultAnalysisProfileMigration(): Migration
{
    return require database_path('migrations/2026_10_07_100100_seed_default_analysis_profile.php');
}

it('stores exactly one default profile with the built-in values after the migrations', function () {
    $default = AnalysisProfile::query()->sole();

    expect($default->is_default)->toBeTrue()
        ->and($default->name)->toBe('Default')
        ->and($default->budget_cents)->toBe(50_000)
        ->and($default->target_coverage_ppb)->toBe(950_000_000)
        ->and($default->maximum_drawdown_ppb)->toBeNull()
        ->and($default->maximum_risk_score)->toBeNull()
        ->and($default->maximum_single_position_ppb)->toBeNull()
        ->and($default->minimum_history_months)->toBeNull()
        ->and($default->minimum_positive_months_ppb)->toBeNull()
        ->and($default->maximum_allocation_per_trader_ppb)->toBeNull()
        ->and($default->toCriteria()->budget->cents())->toBe(50_000);
});

it('is idempotent and keeps an existing default', function () {
    $edited = AnalysisProfile::query()->sole();
    $edited->update(['budget_cents' => 120_000]);

    seedDefaultAnalysisProfileMigration()->up();

    expect(AnalysisProfile::query()->sole()->budget_cents)->toBe(120_000);
});

it('promotes the oldest profile when profiles exist but none is the default', function () {
    withoutStoredAnalysisProfiles();
    $oldest = AnalysisProfile::factory()->create();
    AnalysisProfile::factory()->create();

    seedDefaultAnalysisProfileMigration()->up();

    expect(AnalysisProfile::query()->count())->toBe(2)
        ->and(AnalysisProfile::query()->where('is_default', true)->sole()->is($oldest))->toBeTrue();
});
