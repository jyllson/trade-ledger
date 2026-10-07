<?php

use App\Application\AnalysisProfiles\MakeAnalysisProfileDefault;
use App\Filament\Resources\AnalysisProfiles\AnalysisProfileResource;
use App\Filament\Resources\AnalysisProfiles\Pages\CreateAnalysisProfile;
use App\Filament\Resources\AnalysisProfiles\Pages\EditAnalysisProfile;
use App\Filament\Resources\AnalysisProfiles\Pages\ListAnalysisProfiles;
use App\Models\AnalysisProfile;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    withoutStoredAnalysisProfiles();
});

/**
 * @return array<string, string>
 */
function analysisProfileFormData(array $overrides = []): array
{
    return [
        'name' => 'Conservative',
        'budget_cents' => '750.50',
        'target_coverage_ppb' => '99',
        'maximum_drawdown_ppb' => '25',
        'maximum_risk_score' => '6',
        'maximum_single_position_ppb' => '12.5',
        'minimum_history_months' => '24',
        'minimum_positive_months_ppb' => '55.5555555',
        'maximum_allocation_per_trader_ppb' => '',
        ...$overrides,
    ];
}

it('redirects guests away from the analysis profiles', function () {
    $this->get(AnalysisProfileResource::getUrl('index'))->assertRedirect('/admin/login');
});

it('never writes when the list is visited — with or without a stored default', function () {
    $this->actingAs(User::factory()->create());
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    // The migration stores the default (D-048); an empty table stays empty.
    $this->get(AnalysisProfileResource::getUrl('index'))->assertOk();
    Livewire::test(ListAnalysisProfiles::class)->assertOk();

    expect($writes)->toBe([])
        ->and(AnalysisProfile::query()->count())->toBe(0);

    DB::table('analysis_profiles')->insert(['name' => 'Default', 'budget_cents' => 50_000, 'target_coverage_ppb' => 950_000_000, 'is_default' => true]);
    $default = AnalysisProfile::query()->sole();
    $writes = [];

    $this->get(AnalysisProfileResource::getUrl('index'))->assertOk();
    Livewire::test(ListAnalysisProfiles::class)
        ->assertCanSeeTableRecords([$default]);

    expect($writes)->toBe([])
        ->and(AnalysisProfile::query()->sole()->is($default))->toBeTrue();
});

it('creates a profile with exact cents and ppb and empty criteria as not applied', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateAnalysisProfile::class)
        ->fillForm(analysisProfileFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = AnalysisProfile::query()->where('name', 'Conservative')->sole();

    expect($profile->budget_cents)->toBe(75_050)
        ->and($profile->target_coverage_ppb)->toBe(990_000_000)
        ->and($profile->maximum_drawdown_ppb)->toBe(250_000_000)
        ->and($profile->maximum_risk_score)->toBe(6)
        ->and($profile->maximum_single_position_ppb)->toBe(125_000_000)
        ->and($profile->minimum_history_months)->toBe(24)
        ->and($profile->minimum_positive_months_ppb)->toBe(555_555_555)
        ->and($profile->maximum_allocation_per_trader_ppb)->toBeNull()
        // a new profile is never the default
        ->and($profile->is_default)->toBeFalse();
});

it('validates the profile inputs', function (array $overrides, array $errors) {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateAnalysisProfile::class)
        ->fillForm(analysisProfileFormData($overrides))
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(AnalysisProfile::query()->where('name', 'Conservative')->exists())->toBeFalse();
})->with([
    'missing name' => [['name' => ''], ['name' => 'required']],
    'missing budget' => [['budget_cents' => ''], ['budget_cents' => 'required']],
    'budget below $200' => [['budget_cents' => '199.99'], ['budget_cents']],
    'budget above $10M' => [['budget_cents' => '10000000.01'], ['budget_cents']],
    'budget with 3 decimals' => [['budget_cents' => '500.001'], ['budget_cents']],
    'budget not a number' => [['budget_cents' => 'lots'], ['budget_cents']],
    'zero target' => [['target_coverage_ppb' => '0'], ['target_coverage_ppb']],
    'target above 100' => [['target_coverage_ppb' => '100.0000001'], ['target_coverage_ppb']],
    'negative drawdown' => [['maximum_drawdown_ppb' => '-1'], ['maximum_drawdown_ppb']],
    'drawdown above 100' => [['maximum_drawdown_ppb' => '101'], ['maximum_drawdown_ppb']],
    'single position above 100' => [['maximum_single_position_ppb' => '100.5'], ['maximum_single_position_ppb']],
    'positive months with 8 decimals' => [['minimum_positive_months_ppb' => '50.00000001'], ['minimum_positive_months_ppb']],
    'risk score 0' => [['maximum_risk_score' => '0'], ['maximum_risk_score']],
    'risk score 11' => [['maximum_risk_score' => '11'], ['maximum_risk_score']],
    'history 0 months' => [['minimum_history_months' => '0'], ['minimum_history_months']],
    'history 601 months' => [['minimum_history_months' => '601'], ['minimum_history_months']],
    'zero allocation' => [['maximum_allocation_per_trader_ppb' => '0'], ['maximum_allocation_per_trader_ppb']],
]);

it('accepts the bounds exactly', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateAnalysisProfile::class)
        ->fillForm(analysisProfileFormData([
            'budget_cents' => '200',
            'target_coverage_ppb' => '100',
            'maximum_drawdown_ppb' => '0',
            'maximum_risk_score' => '10',
            'maximum_single_position_ppb' => '100',
            'minimum_history_months' => '600',
            'minimum_positive_months_ppb' => '0',
            'maximum_allocation_per_trader_ppb' => '100',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = AnalysisProfile::query()->where('name', 'Conservative')->sole();

    expect($profile->budget_cents)->toBe(20_000)
        ->and($profile->maximum_drawdown_ppb)->toBe(0)
        ->and($profile->minimum_positive_months_ppb)->toBe(0)
        ->and($profile->maximum_allocation_per_trader_ppb)->toBe(1_000_000_000);
});

it('rejects a duplicate name', function () {
    $this->actingAs(User::factory()->create());
    AnalysisProfile::factory()->create(['name' => 'Conservative']);

    Livewire::test(CreateAnalysisProfile::class)
        ->fillForm(analysisProfileFormData())
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

it('edits a profile, showing stored cents and ppb as USD and percent, and clears a criterion', function () {
    $this->actingAs(User::factory()->create());
    $profile = AnalysisProfile::factory()->create([
        'name' => 'Edit me',
        'budget_cents' => 50_025,
        'target_coverage_ppb' => 950_000_000,
        'maximum_drawdown_ppb' => 125_000_000,
    ]);

    Livewire::test(EditAnalysisProfile::class, ['record' => $profile->getRouteKey()])
        ->assertSchemaStateSet([
            'budget_cents' => '500.25',
            'target_coverage_ppb' => '95',
            'maximum_drawdown_ppb' => '12.5',
            'minimum_history_months' => null,
        ])
        ->fillForm(['budget_cents' => '1000', 'maximum_drawdown_ppb' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $profile->refresh();

    expect($profile->budget_cents)->toBe(100_000)
        ->and($profile->maximum_drawdown_ppb)->toBeNull()
        ->and($profile->target_coverage_ppb)->toBe(950_000_000);
});

it('moves the default with the "Make default" table action', function () {
    $this->actingAs(User::factory()->create());
    $old = AnalysisProfile::factory()->asDefault()->create();
    $new = AnalysisProfile::factory()->create(['name' => 'Next']);

    Livewire::test(ListAnalysisProfiles::class)
        ->assertTableActionHidden('makeDefault', $old)
        ->assertTableActionVisible('makeDefault', $new)
        ->callTableAction('makeDefault', $new)
        ->assertNotified('"Next" is now the default profile');

    expect($new->refresh()->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse()
        ->and(AnalysisProfile::query()->where('is_default', true)->count())->toBe(1);
});

it('requires confirmation for "Make default": mounting it alone changes nothing', function () {
    $this->actingAs(User::factory()->create());
    $old = AnalysisProfile::factory()->asDefault()->create();
    $new = AnalysisProfile::factory()->create();

    Livewire::test(ListAnalysisProfiles::class)
        ->mountTableAction('makeDefault', $new);

    expect($old->refresh()->is_default)->toBeTrue()
        ->and($new->refresh()->is_default)->toBeFalse();
});

it('offers "Make default" on the edit page of a non-default profile', function () {
    $this->actingAs(User::factory()->create());
    $old = AnalysisProfile::factory()->asDefault()->create();
    $new = AnalysisProfile::factory()->create();

    Livewire::test(EditAnalysisProfile::class, ['record' => $old->getRouteKey()])
        ->assertActionHidden('makeDefault')
        ->assertActionHidden(DeleteAction::class);

    Livewire::test(EditAnalysisProfile::class, ['record' => $new->getRouteKey()])
        ->callAction('makeDefault');

    expect($new->refresh()->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse();
});

it('deletes a non-default profile but never offers deleting the default', function () {
    $this->actingAs(User::factory()->create());
    $default = AnalysisProfile::factory()->asDefault()->create();
    $other = AnalysisProfile::factory()->create();

    Livewire::test(ListAnalysisProfiles::class)
        ->assertTableActionHidden('delete', $default)
        ->callTableAction('delete', $other);

    expect(AnalysisProfile::query()->pluck('id')->all())->toBe([$default->id]);
});

it('bulk-deletes non-default profiles through the service, and deletes none when the default is selected', function () {
    $this->actingAs(User::factory()->create());
    $default = AnalysisProfile::factory()->asDefault()->create();
    [$first, $second] = AnalysisProfile::factory()->count(2)->create()->all();

    Livewire::test(ListAnalysisProfiles::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$first, $default])
        ->assertNotified();

    expect(AnalysisProfile::query()->count())->toBe(3);

    Livewire::test(ListAnalysisProfiles::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$first, $second]);

    expect(AnalysisProfile::query()->pluck('id')->all())->toBe([$default->id]);
});

it('does not delete a profile promoted to default after the page was loaded', function () {
    $this->actingAs(User::factory()->create());
    AnalysisProfile::factory()->asDefault()->create();
    $other = AnalysisProfile::factory()->create();

    $edit = Livewire::test(EditAnalysisProfile::class, ['record' => $other->getRouteKey()])
        ->assertActionVisible(DeleteAction::class);

    app(MakeAnalysisProfileDefault::class)->handle($other);

    $edit->callAction(DeleteAction::class);

    expect(AnalysisProfile::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and($other->refresh()->is_default)->toBeTrue();
});
