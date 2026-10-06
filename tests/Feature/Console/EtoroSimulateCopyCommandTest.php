<?php

use App\Models\CopySimulation;
use App\Models\Trader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Application/Traders/CopySimulationFixtures.php';

beforeEach(function () {
    // Offline by design: works with the integration disabled, never sends HTTP.
    config(['etoro.enabled' => false]);
    Http::preventStrayRequests();
});

it('simulates and stores a copy amount over the latest stored snapshot', function () {
    $trader = Trader::factory()->create(['username' => 'sim_trader']);
    copySimulationSnapshot($trader, ['captured_at' => '2026-10-01 08:00:00']);
    $latest = copySimulationSnapshot($trader, ['captured_at' => '2026-10-05 10:00:00']);

    $exitCode = Artisan::call('etoro:simulate-copy', ['username' => 'sim_trader', 'amount' => '200', '--target' => '99']);
    $output = Artisan::output();

    $simulation = CopySimulation::query()->sole();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("Copy simulation #{$simulation->id} (copy-simulation-v1)")
        ->toContain("#{$latest->id} captured 2026-10-05T10:00:00Z")
        ->toMatch('/Eligible \/ skipped positions \.+ 5 \/ 5/')
        ->toMatch('/Coverage of visible positions \.+ 98\.7405541%/')
        ->toMatch('/Minimum for 99% coverage \.+ \$222\.23/')
        ->toContain('| 5 | pos-f    | 1006       | 0.45%  | below_minimum | At a copy amount of $200.00 this position (0.45% of the portfolio) would be about $0.90')
        ->toContain('| 9 | pos-z    | 1010       | 0%     | zero_weight   |');

    expect($simulation->portfolio_snapshot_id)->toBe($latest->id)
        ->and($simulation->copy_amount_cents)->toBe(20_000)
        ->and($simulation->target_coverage_ppb)->toBe(990_000_000)
        ->and($simulation->minimum_target_amount_cents)->toBe(22_223);
});

it('uses an explicit snapshot, decimal amounts and the minimum position option', function () {
    $trader = Trader::factory()->create(['username' => 'sim_trader']);
    $older = copySimulationSnapshot($trader, ['captured_at' => '2026-10-01 08:00:00']);
    copySimulationSnapshot($trader, ['captured_at' => '2026-10-05 10:00:00']);

    $this->artisan('etoro:simulate-copy', [
        'username' => 'sim_trader',
        'amount' => '500.25',
        '--minimum-position' => '5',
        '--snapshot' => (string) $older->id,
    ])->assertSuccessful();

    $simulation = CopySimulation::query()->sole();

    expect($simulation->portfolio_snapshot_id)->toBe($older->id)
        ->and($simulation->copy_amount_cents)->toBe(50_025)
        ->and($simulation->minimum_position_amount_cents)->toBe(500)
        ->and($simulation->target_coverage_ppb)->toBeNull();
});

it('rejects invalid input without storing anything', function (array $arguments, string $message) {
    Trader::factory()->create(['username' => 'sim_trader']);

    $this->artisan('etoro:simulate-copy', ['username' => 'sim_trader', 'amount' => '200', ...$arguments])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(CopySimulation::count())->toBe(0);
})->with([
    'float-like amount' => [['amount' => '1e3'], 'amount must be a USD amount'],
    'three decimals' => [['amount' => '200.123'], 'amount must be a USD amount'],
    'negative amount' => [['amount' => '-200'], 'amount must be a USD amount'],
    'zero minimum position' => [['--minimum-position' => '0'], '--minimum-position must be greater than 0'],
    'target zero' => [['--target' => '0'], '--target must be percentage points'],
    'target above 100' => [['--target' => '100.5'], '--target must be percentage points'],
    'target as fraction syntax' => [['--target' => '.95'], '--target must be percentage points'],
    'no snapshot' => [[], 'has no stored portfolio snapshot'],
    'foreign snapshot' => [['--snapshot' => '999'], 'No stored snapshot with that id'],
]);

it('rejects an unknown trader', function () {
    $this->artisan('etoro:simulate-copy', ['username' => 'nobody', 'amount' => '200'])
        ->expectsOutputToContain('No stored trader with that username')
        ->assertFailed();
});

it('never refers to the eToro transport', function () {
    $source = file_get_contents(app_path('Console/Commands/EtoroSimulateCopyCommand.php'));

    expect($source)->not->toContain('EtoroClient')
        ->not->toContain('Facades\\Http')
        ->not->toContain('LivePortfolioMapper')
        ->not->toContain('(float)');
});
