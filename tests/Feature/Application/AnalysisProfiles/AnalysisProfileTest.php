<?php

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\AnalysisProfiles\AnalysisProfileCriteria;
use App\Application\AnalysisProfiles\AnalysisProfileInput;
use App\Application\AnalysisProfiles\DefaultAnalysisProfileCannotBeDeleted;
use App\Application\AnalysisProfiles\DeleteAnalysisProfiles;
use App\Application\AnalysisProfiles\EnsureDefaultAnalysisProfile;
use App\Application\AnalysisProfiles\MakeAnalysisProfileDefault;
use App\Application\AnalysisProfiles\ResolveDefaultAnalysisProfile;
use App\Models\AnalysisProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    withoutStoredAnalysisProfiles();
});

function defaultProfileCount(): int
{
    return AnalysisProfile::query()->where('is_default', true)->count();
}

// --- ensure / resolve ----------------------------------------------------

it('creates the built-in default profile when none exists, idempotently', function () {
    $first = app(EnsureDefaultAnalysisProfile::class)->handle();
    $second = app(EnsureDefaultAnalysisProfile::class)->handle();

    expect($first->is($second))->toBeTrue()
        ->and(AnalysisProfile::query()->count())->toBe(1)
        ->and($first->refresh()->is_default)->toBeTrue()
        ->and($first->name)->toBe('Default')
        ->and($first->budget_cents)->toBe(50_000)
        ->and($first->target_coverage_ppb)->toBe(950_000_000)
        ->and($first->maximum_drawdown_ppb)->toBeNull()
        ->and($first->maximum_risk_score)->toBeNull()
        ->and($first->maximum_single_position_ppb)->toBeNull()
        ->and($first->minimum_history_months)->toBeNull()
        ->and($first->minimum_positive_months_ppb)->toBeNull()
        ->and($first->maximum_allocation_per_trader_ppb)->toBeNull();
});

it('promotes the oldest profile when profiles exist but none is the default', function () {
    $oldest = AnalysisProfile::factory()->create(['name' => 'Oldest']);
    AnalysisProfile::factory()->create(['name' => 'Newer']);

    $default = app(EnsureDefaultAnalysisProfile::class)->handle();

    expect($default->is($oldest))->toBeTrue()
        ->and(AnalysisProfile::query()->count())->toBe(2)
        ->and(defaultProfileCount())->toBe(1);
});

it('returns an existing default unchanged', function () {
    AnalysisProfile::factory()->create();
    $default = AnalysisProfile::factory()->asDefault()->create(['budget_cents' => 120_000]);

    expect(app(EnsureDefaultAnalysisProfile::class)->handle()->is($default))->toBeTrue()
        ->and(defaultProfileCount())->toBe(1);
});

it('resolves the stored default, or the built-in default without writing', function () {
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $builtIn = app(ResolveDefaultAnalysisProfile::class)->handle();

    expect($builtIn->isBuiltIn())->toBeTrue()
        ->and($builtIn->budget)->toEqual(Money::fromCents(50_000))
        ->and($builtIn->targetCoverage)->toEqual(Percentage::fromPartsPerBillion(950_000_000))
        ->and($writes)->toBe([])
        ->and(AnalysisProfile::query()->count())->toBe(0);

    $stored = AnalysisProfile::factory()->asDefault()->create([
        'budget_cents' => 123_456,
        'maximum_drawdown_ppb' => 250_000_000,
        'maximum_risk_score' => 6,
        'minimum_history_months' => 12,
    ]);
    $resolved = app(ResolveDefaultAnalysisProfile::class)->handle();

    expect($resolved->profileId)->toBe($stored->id)
        ->and($resolved->budget)->toEqual(Money::fromCents(123_456))
        ->and($resolved->maximumDrawdown)->toEqual(Percentage::fromPartsPerBillion(250_000_000))
        ->and($resolved->maximumRiskScore)->toBe(6)
        ->and($resolved->minimumHistoryMonths)->toBe(12)
        ->and($resolved->maximumSinglePosition)->toBeNull();
});

// --- exactly one default -------------------------------------------------

it('moves the default flag atomically to another profile', function () {
    $old = AnalysisProfile::factory()->asDefault()->create();
    $new = AnalysisProfile::factory()->create();

    app(MakeAnalysisProfileDefault::class)->handle($new);

    expect($new->refresh()->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse()
        ->and(defaultProfileCount())->toBe(1);

    // Making the default the default again changes nothing.
    app(MakeAnalysisProfileDefault::class)->handle($new);

    expect(defaultProfileCount())->toBe(1)
        ->and($new->refresh()->is_default)->toBeTrue();
});

it('keeps the current default when the switch fails midway', function () {
    $old = AnalysisProfile::factory()->asDefault()->create();
    $gone = AnalysisProfile::factory()->create();
    AnalysisProfile::query()->whereKey($gone->id)->delete();

    expect(fn () => app(MakeAnalysisProfileDefault::class)->handle($gone))->toThrow(ModelNotFoundException::class);

    expect($old->refresh()->is_default)->toBeTrue()
        ->and(defaultProfileCount())->toBe(1);
});

it('rejects a second default at the database level', function () {
    AnalysisProfile::factory()->asDefault()->create();
    $other = AnalysisProfile::factory()->create();

    expect(fn () => AnalysisProfile::query()->whereKey($other->id)->update(['is_default' => true]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(defaultProfileCount())->toBe(1)
        // any number of non-default profiles is fine
        ->and(AnalysisProfile::factory()->count(3)->create())->toHaveCount(3);
});

it('never deletes the default profile, but deletes any other', function () {
    $default = AnalysisProfile::factory()->asDefault()->create();
    $other = AnalysisProfile::factory()->create();

    expect(fn () => $default->delete())->toThrow(DefaultAnalysisProfileCannotBeDeleted::class);

    $other->delete();

    expect(AnalysisProfile::query()->pluck('id')->all())->toBe([$default->id]);
});

it('refuses to delete a profile promoted to default after it was loaded', function () {
    $old = AnalysisProfile::factory()->asDefault()->create();
    $loaded = AnalysisProfile::query()->findOrFail(AnalysisProfile::factory()->create()->id);
    expect($loaded->is_default)->toBeFalse();

    // A concurrent "Make default" promotes it; the loaded model is stale.
    app(MakeAnalysisProfileDefault::class)->handle(AnalysisProfile::query()->findOrFail($loaded->id));

    expect($loaded->is_default)->toBeFalse()
        ->and(fn () => app(DeleteAnalysisProfiles::class)->handle([$loaded]))->toThrow(DefaultAnalysisProfileCannotBeDeleted::class)
        // the direct model delete reads the stored flag too
        ->and(fn () => $loaded->delete())->toThrow(DefaultAnalysisProfileCannotBeDeleted::class);

    expect(AnalysisProfile::query()->whereKey($loaded->id)->exists())->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse()
        ->and(defaultProfileCount())->toBe(1);
});

it('deletes non-default profiles all or nothing', function () {
    $default = AnalysisProfile::factory()->asDefault()->create();
    [$first, $second] = AnalysisProfile::factory()->count(2)->create()->all();

    expect(fn () => app(DeleteAnalysisProfiles::class)->handle([$first, $default]))->toThrow(DefaultAnalysisProfileCannotBeDeleted::class)
        ->and(AnalysisProfile::query()->count())->toBe(3)
        ->and(app(DeleteAnalysisProfiles::class)->handle([$first, $second]))->toBe(2)
        // already deleted: skipped
        ->and(app(DeleteAnalysisProfiles::class)->handle([$first]))->toBe(0)
        ->and(app(DeleteAnalysisProfiles::class)->handle([]))->toBe(0)
        ->and(AnalysisProfile::query()->pluck('id')->all())->toBe([$default->id]);
});

it('does not mass-assign the default flag', function () {
    $profile = AnalysisProfile::query()->create([
        'name' => 'Sneaky',
        'budget_cents' => 50_000,
        'target_coverage_ppb' => 950_000_000,
        'is_default' => true,
    ]);

    expect($profile->refresh()->is_default)->toBeFalse();
});

// --- criteria bounds -----------------------------------------------------

it('rejects criteria outside their bounds', function (array $override) {
    new AnalysisProfileCriteria(...[
        'profileId' => null,
        'name' => 'x',
        'budget' => Money::fromCents(50_000),
        'targetCoverage' => Percentage::fromPartsPerBillion(950_000_000),
        ...$override,
    ]);
})->throws(InvalidArgumentException::class)->with([
    'budget below $200' => [['budget' => Money::fromCents(19_999)]],
    'budget above $10M' => [['budget' => Money::fromCents(1_000_000_001)]],
    'zero target' => [['targetCoverage' => Percentage::zero()]],
    'target above 100%' => [['targetCoverage' => Percentage::fromPartsPerBillion(1_000_000_001)]],
    'negative drawdown' => [['maximumDrawdown' => Percentage::fromPartsPerBillion(-1)]],
    'drawdown above 100%' => [['maximumDrawdown' => Percentage::fromPartsPerBillion(1_000_000_001)]],
    'risk score 0' => [['maximumRiskScore' => 0]],
    'risk score 11' => [['maximumRiskScore' => 11]],
    'single position above 100%' => [['maximumSinglePosition' => Percentage::fromPartsPerBillion(1_000_000_001)]],
    'history 0 months' => [['minimumHistoryMonths' => 0]],
    'history 601 months' => [['minimumHistoryMonths' => 601]],
    'positive months above 100%' => [['minimumPositiveMonths' => Percentage::fromPartsPerBillion(1_000_000_001)]],
    'zero allocation' => [['maximumAllocationPerTrader' => Percentage::zero()]],
]);

it('accepts criteria exactly at their bounds', function () {
    $criteria = new AnalysisProfileCriteria(
        profileId: null,
        name: 'bounds',
        budget: Money::fromCents(20_000),
        targetCoverage: Percentage::whole(),
        maximumDrawdown: Percentage::zero(),
        maximumRiskScore: 10,
        maximumSinglePosition: Percentage::whole(),
        minimumHistoryMonths: 600,
        minimumPositiveMonths: Percentage::zero(),
        maximumAllocationPerTrader: Percentage::whole(),
    );

    expect($criteria->budget->cents())->toBe(20_000)
        ->and((new AnalysisProfileCriteria(null, 'max', Money::fromCents(1_000_000_000), Percentage::fromPartsPerBillion(1)))->budget->cents())->toBe(1_000_000_000);
});

// --- exact input conversion ----------------------------------------------

it('converts percent and USD inputs exactly, both ways', function () {
    expect(AnalysisProfileInput::parsePercent('95')?->partsPerBillion())->toBe(950_000_000)
        ->and(AnalysisProfileInput::parsePercent('12.5')?->partsPerBillion())->toBe(125_000_000)
        ->and(AnalysisProfileInput::parsePercent('0.0000001')?->partsPerBillion())->toBe(1)
        ->and(AnalysisProfileInput::parsePercent('100')?->partsPerBillion())->toBe(1_000_000_000)
        ->and(AnalysisProfileInput::parsePercent('0')?->partsPerBillion())->toBe(0)
        ->and(AnalysisProfileInput::parsePercent('100.0000001'))->toBeNull()
        ->and(AnalysisProfileInput::parsePercent('-1'))->toBeNull()
        ->and(AnalysisProfileInput::parsePercent('1.00000001'))->toBeNull()
        ->and(AnalysisProfileInput::parsePercent('abc'))->toBeNull()
        ->and(AnalysisProfileInput::formatPercent(950_000_000))->toBe('95')
        ->and(AnalysisProfileInput::formatPercent(125_000_000))->toBe('12.5')
        ->and(AnalysisProfileInput::formatPercent(1))->toBe('0.0000001')
        ->and(AnalysisProfileInput::formatPercent(0))->toBe('0')
        ->and(AnalysisProfileInput::parseUsd('500')?->cents())->toBe(50_000)
        ->and(AnalysisProfileInput::parseUsd('500.25')?->cents())->toBe(50_025)
        ->and(AnalysisProfileInput::parseUsd('500.255'))->toBeNull()
        ->and(AnalysisProfileInput::formatUsd(50_000))->toBe('500')
        ->and(AnalysisProfileInput::formatUsd(50_025))->toBe('500.25');
});
