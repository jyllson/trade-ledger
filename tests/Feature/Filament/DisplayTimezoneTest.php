<?php

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Support\DateTimeDisplay;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Every timestamp in the admin panel is stored in UTC and shown in
 * Europe/Malta with the zone abbreviation (PROJECT.md §9, D-046).
 * Summer (CEST, UTC+2) and winter (CET, UTC+1) instants are checked on
 * representative tables, infolists and widgets.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    config(['etoro.enabled' => false]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

it('keeps storage in UTC and displays in Europe/Malta', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Europe/Malta')
        ->and(DateTimeDisplay::timezone())->toBe('Europe/Malta');
});

it('formats UTC instants in Europe/Malta across the DST change', function (string $utc, string $expected) {
    expect(DateTimeDisplay::format(CarbonImmutable::parse($utc, 'UTC')))->toBe($expected);
})->with([
    'summer (CEST)' => ['2026-07-15 10:00:00', '2026-07-15 12:00 CEST'],
    'winter (CET)' => ['2026-01-15 10:00:00', '2026-01-15 11:00 CET'],
    'last CEST minute' => ['2026-10-25 00:59:00', '2026-10-25 02:59 CEST'],
    'first CET minute' => ['2026-10-25 01:00:00', '2026-10-25 02:00 CET'],
    'spring forward' => ['2026-03-29 01:00:00', '2026-03-29 03:00 CEST'],
    'UTC date rolls over' => ['2026-12-31 23:30:00', '2027-01-01 00:30 CET'],
]);

it('formats a non-UTC instant by its absolute time and renders null as the placeholder', function () {
    expect(DateTimeDisplay::format(new DateTimeImmutable('2026-07-15 08:00:00', new DateTimeZone('America/New_York'))))->toBe('2026-07-15 14:00 CEST')
        ->and(DateTimeDisplay::format(null))->toBe('—')
        ->and(DateTimeDisplay::format(null, 'Never synced'))->toBe('Never synced');
});

it('stores the UTC instant unchanged', function () {
    $trader = Trader::factory()->create(['profile_synced_at' => '2026-07-15 10:00:00']);

    expect($trader->fresh()?->profile_synced_at?->format('Y-m-d H:i:s e'))->toBe('2026-07-15 10:00:00 UTC');
});

it('shows trader table timestamps in Europe/Malta', function () {
    Trader::factory()->create([
        'last_seen_at' => '2026-07-15 10:00:00',
        'profile_synced_at' => '2026-01-15 10:00:00',
    ]);

    Livewire::test(ListTraders::class)
        ->assertOk()
        ->assertSee('2026-07-15 12:00 CEST')
        ->assertSee('2026-01-15 11:00 CET')
        ->assertDontSee('2026-07-15 10:00');
});

it('shows trader infolist, performance and portfolio sync times in Europe/Malta', function () {
    $trader = Trader::factory()->create([
        'first_seen_at' => '2026-01-15 10:00:00',
        'last_seen_at' => '2026-07-15 10:00:00',
        'profile_synced_at' => '2026-03-29 01:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
        'performance_synced_at' => '2026-10-25 00:30:00',
        'portfolio_visibility' => PerformanceVisibility::Available,
        'portfolio_synced_at' => '2026-10-25 01:30:00',
    ]);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('2026-01-15 11:00 CET')
        ->assertSee('2026-07-15 12:00 CEST')
        ->assertSee('2026-03-29 03:00 CEST')
        ->assertSeeInOrder(['Performance sync', 'Last successful sync', '2026-10-25 02:30 CEST'])
        ->assertSeeInOrder(['Portfolio sync', 'Last successful sync', '2026-10-25 02:30 CET']);
});

it('shows import run table and infolist timestamps in Europe/Malta', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::Completed,
        'started_at' => '2026-07-15 10:00:00',
        'finished_at' => '2026-01-15 10:00:00',
    ]);

    Livewire::test(ListImportRuns::class)
        ->assertOk()
        ->assertSee('2026-07-15 12:00 CEST')
        ->assertSee('2026-01-15 11:00 CET');

    Livewire::test(ViewImportRun::class, ['record' => $run->id])
        ->assertOk()
        ->assertSee('2026-07-15 12:00 CEST')
        ->assertSee('2026-01-15 11:00 CET');
});
