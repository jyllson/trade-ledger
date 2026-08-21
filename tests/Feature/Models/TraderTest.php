<?php

use App\Models\Trader;
use App\Models\TraderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates a traders table with the expected columns', function (): void {
    expect(Schema::hasTable('traders'))->toBeTrue();

    expect(Schema::hasColumns('traders', [
        'id',
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
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

// --- Checkpoint G: profile fields ------------------------------------------

it('leaves all six profile fields null by default on a freshly-imported ranking candidate', function (): void {
    $trader = Trader::factory()->create();

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_gcid)->toBeNull()
        ->and($fromDatabase->profile_is_popular_investor)->toBeNull()
        ->and($fromDatabase->profile_is_verified)->toBeNull()
        ->and($fromDatabase->profile_country_code)->toBeNull()
        ->and($fromDatabase->profile_language_iso_code)->toBeNull()
        ->and($fromDatabase->profile_synced_at)->toBeNull();
});

it('casts profile_is_popular_investor and profile_is_verified to booleans', function (): void {
    $trader = Trader::factory()->create([
        'profile_is_popular_investor' => true,
        'profile_is_verified' => false,
    ]);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_is_popular_investor)->toBeTrue()->toBeBool()
        ->and($fromDatabase->profile_is_verified)->toBeFalse()->toBeBool();
});

it('casts profile_country_code to an integer', function (): void {
    $trader = Trader::factory()->create(['profile_country_code' => 1]);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_country_code)->toBe(1)->toBeInt();
});

it('casts profile_synced_at to a Carbon instance', function (): void {
    $trader = Trader::factory()->create(['profile_synced_at' => '2026-01-01 00:00:00']);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_synced_at)->toBeInstanceOf(CarbonInterface::class);
});

it('stores profile_gcid as a plain string with no unique constraint, independent of external_cid', function (): void {
    $traderA = Trader::factory()->create(['external_cid' => 'cid-a', 'profile_gcid' => 'duplicate-gcid']);
    $traderB = Trader::factory()->create(['external_cid' => 'cid-b', 'profile_gcid' => 'duplicate-gcid']);

    expect($traderA->refresh()->profile_gcid)->toBe('duplicate-gcid')
        ->and($traderB->refresh()->profile_gcid)->toBe('duplicate-gcid')
        ->and($traderA->external_cid)->not->toBe($traderA->profile_gcid);
});

it('rejects a second trader with a duplicate username', function (): void {
    Trader::factory()->create(['username' => 'duplicate_username']);

    expect(fn () => Trader::factory()->create(['username' => 'duplicate_username']))
        ->toThrow(QueryException::class);
});

it('rejects a second trader with a duplicate external_cid', function (): void {
    Trader::factory()->create(['external_cid' => '100001']);

    expect(fn () => Trader::factory()->create(['external_cid' => '100001']))
        ->toThrow(QueryException::class);
});

it('stores external_cid as a string without numeric reinterpretation', function (): void {
    $trader = Trader::factory()->create(['external_cid' => '007']);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->external_cid)->toBe('007')
        ->and($fromDatabase->external_cid)->toBeString();
});

it('defaults a newly inserted trader status to candidate', function (): void {
    $id = DB::table('traders')->insertGetId([
        'external_cid' => '999999',
        'username' => 'default_status_trader',
        'ranking_type' => 'trader',
        'ranking_sub_type' => 'pi-certified',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $trader = Trader::query()->findOrFail($id);

    expect($trader->status)->toBe(TraderStatus::Candidate);
});

it('casts status to the TraderStatus backed enum', function (): void {
    $trader = Trader::factory()->create(['status' => TraderStatus::Watched]);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->status)->toBeInstanceOf(TraderStatus::class)
        ->and($fromDatabase->status)->toBe(TraderStatus::Watched);

    expect(DB::table('traders')->where('id', $trader->id)->value('status'))->toBe('watched');
});

it('casts first_seen_at and last_seen_at to Carbon instances', function (): void {
    $trader = Trader::factory()->create([
        'first_seen_at' => '2026-01-01 00:00:00',
        'last_seen_at' => '2026-02-01 00:00:00',
    ]);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->first_seen_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fromDatabase->last_seen_at)->toBeInstanceOf(CarbonInterface::class);
});

it('casts copiers_count to an integer and defaults it to zero', function (): void {
    $id = DB::table('traders')->insertGetId([
        'external_cid' => '888888',
        'username' => 'default_copiers_trader',
        'ranking_type' => 'trader',
        'ranking_sub_type' => 'pi-certified',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $trader = Trader::query()->findOrFail($id);

    expect($trader->copiers_count)->toBe(0)->toBeInt();
});
