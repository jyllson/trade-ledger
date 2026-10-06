<?php

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\CopySimulationSettings;
use App\Application\Traders\SimulateCopyAmount;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Resources\Traders\Widgets\CopyAmountSimulator;
use App\Filament\Resources\Traders\Widgets\TraderPortfolio;
use App\Jobs\SyncTraderPortfolioJob;
use App\Models\CopySimulation;
use App\Models\Instrument;
use App\Models\PerformanceVisibility;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../../Application/Traders/CopySimulationFixtures.php';

/**
 * Hand-checkable exposure snapshot (invested-only basis = whole portfolio,
 * cash 0):
 *
 *   Apple (AAPL)  Stocks  buy   60%  1x
 *   Bitcoin (BTC) Crypto  sell  30%  2x
 *   bare #3003    unknown buy   10%  leverage unknown
 *
 * HHI by instrument = 0.36 + 0.09 + 0.01 = 46 %, effective = 1 / 0.46 = 2.17;
 * by asset class Partial (classified 90 %, unknown 10 %); leverage
 * contribution 0.6 × 1 + 0.3 × 2 = 1.20x over a known weight of 90 %.
 */
function exposurePortfolioSnapshot(Trader $trader): PortfolioSnapshot
{
    $snapshot = PortfolioSnapshot::factory()->for($trader)->create([
        'captured_at' => '2026-10-05 10:00:00',
        'last_confirmed_at' => '2026-10-06 08:30:00',
        'cash_weight_ppb' => 0,
        'invested_weight_ppb' => 1_000_000_000,
        'position_count' => 3,
    ]);

    $apple = Instrument::factory()->enriched()->create(['external_instrument_id' => '1001', 'name' => 'Apple', 'symbol' => 'AAPL', 'asset_class' => 'Stocks']);
    $bitcoin = Instrument::factory()->enriched()->create(['external_instrument_id' => '1002', 'name' => 'Bitcoin', 'symbol' => 'BTC', 'asset_class' => 'Crypto']);
    $bare = Instrument::factory()->create(['external_instrument_id' => '3003']);

    foreach ([[$apple, 600_000_000, true, 1], [$bitcoin, 300_000_000, false, 2], [$bare, 100_000_000, true, null]] as $index => [$instrument, $weight, $isBuy, $leverage]) {
        PortfolioPosition::factory()->for($snapshot, 'snapshot')->create([
            'position_index' => $index,
            'external_position_id' => 'p-'.$index,
            'instrument_id' => $instrument->id,
            'external_instrument_id' => $instrument->external_instrument_id,
            'weight_ppb' => $weight,
            'is_buy' => $isBuy,
            'leverage' => $leverage,
        ]);
    }

    $trader->forceFill(['portfolio_visibility' => PerformanceVisibility::Available, 'portfolio_synced_at' => '2026-10-06 08:30:00'])->save();

    return $snapshot;
}

function availablePortfolioTrader(): Trader
{
    return Trader::factory()->create(['portfolio_visibility' => PerformanceVisibility::Available]);
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
    ]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

it('shows the portfolio sync status on the trader page', function () {
    $trader = Trader::factory()->create();
    exposurePortfolioSnapshot($trader);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('Portfolio sync')
        ->assertSee('Available');

    Http::assertNothingSent();
});

it('renders the stored portfolio, concentration and leverage with explicit basis — no HTTP call', function () {
    $trader = Trader::factory()->create();
    exposurePortfolioSnapshot($trader);

    Livewire::test(TraderPortfolio::class, ['record' => $trader])
        ->assertOk()
        ->assertSee('2026-10-05 12:00 CEST')       // captured, Europe/Malta
        ->assertSee('2026-10-06 10:30 CEST')       // last confirmed
        ->assertSee('invested-only basis')
        ->assertSeeInOrder(['Apple (AAPL)', 'Stocks', 'Buy', '60.0000 %', '1x'])
        ->assertSeeInOrder(['Bitcoin (BTC)', 'Crypto', 'Sell', '30.0000 %', '2x'])
        ->assertSeeInOrder(['Instrument #3003 (metadata pending)', 'Unknown', 'Buy', '10.0000 %', 'Unknown'])
        ->assertSeeInOrder(['By instrument', 'Complete', '46.00 %', '2.17', 'Apple (AAPL) — 60.00 %', '100.00 %'])
        ->assertSeeInOrder(['By asset class', 'Partial'])
        ->assertSee('only 90.00 % of invested weight is classified (10.00 % unknown, counted as one group)')
        ->assertSeeInOrder(['By sector', 'Unavailable', 'no verified sector classification'])
        ->assertSee('Not determinable — 10.00 % of invested weight has unknown leverage')
        ->assertSeeInOrder(['Known leverage contribution', '1.20x'])
        ->assertSeeInOrder(['Known leverage weight', '90.00 %'])
        ->assertSee('Unknown leverage is never assumed to be 1x');

    Http::assertNothingSent();
});

it('shows weighted leverage when every position has a known leverage', function () {
    $snapshot = copySimulationStoredSnapshot([['p-1', '1', 500_000_000], ['p-2', '2', 500_000_000]], ['cash_weight_ppb' => 0]);
    PortfolioPosition::query()->where('external_position_id', 'p-2')->update(['leverage' => 3]);

    Livewire::test(TraderPortfolio::class, ['record' => $snapshot->trader])
        ->assertSeeInOrder(['Weighted leverage Σ(wᵢ × leverageᵢ)', '2.00x'])
        ->assertDontSee('Not determinable');
});

it('shows an empty portfolio state when no snapshot is stored', function () {
    $trader = Trader::factory()->create();

    Livewire::test(TraderPortfolio::class, ['record' => $trader])
        ->assertSee('No portfolio snapshot stored yet. Use “Sync portfolio”.');

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSee('The simulator needs a stored, visible portfolio snapshot')
        ->assertDontSee('Save simulation');
});

it('hides stored snapshots of a portfolio that is now private', function () {
    $trader = Trader::factory()->create();
    exposurePortfolioSnapshot($trader);
    $trader->forceFill(['portfolio_visibility' => PerformanceVisibility::Private])->save();

    Livewire::test(TraderPortfolio::class, ['record' => $trader])
        ->assertSee('found this portfolio private (the trader opted out)')
        ->assertSee('1 older stored snapshot(s) are kept but not shown')
        ->assertDontSee('Apple (AAPL)');

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSee('The simulator needs a stored, visible portfolio snapshot')
        ->assertDontSee('Presets');

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertSee('Private (opted out)');

    Http::assertNothingSent();
});

it('simulates the $200 preset by default and explains every skipped position', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSet('amount', '200')
        ->assertSet('minimumPositionAmount', '1')
        ->assertSee('5 positions — 98.00 % of the portfolio')
        ->assertSee('5 positions — 1.25 % of the portfolio')
        ->assertSee('98.74 %')                    // 980 / 992.5, coverage of invested weight
        ->assertSee('Complete snapshot data')
        ->assertSee('Skipped positions (5)')
        ->assertSee('At a copy amount of $200.00 this position (0.45% of the portfolio) would be about $0.90, below the $1.00 minimum position amount, so it is not copied. It is copied from a copy amount of $222.23.')
        ->assertSee('At a copy amount of $200.00 this position (0.1% of the portfolio) would be about $0.20, below the $1.00 minimum position amount, so it is not copied. It is copied from a copy amount of $1,000.00.')
        ->assertSee('This position has a 0% weight in the snapshot, so no amount is allocated to it and it is not copied.');

    Http::assertNothingSent();
});

it('switches between the $200/$500/$1,000 presets without any HTTP call', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSeeInOrder(['$200', '$500', '$1,000'])
        ->call('selectPreset', 50_000)
        ->assertSet('amount', '500')
        ->assertSee('Skipped positions (2)')
        ->call('selectPreset', 100_000)
        ->assertSet('amount', '1000')
        ->assertSee('9 positions — 99.25 % of the portfolio')
        ->assertSee('Skipped positions (1)')
        ->call('selectPreset', 12_345)          // not a preset: ignored
        ->assertSet('amount', '1000');

    Http::assertNothingSent();
});

it('shows the preset matrix for every preset', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSeeInOrder(['$200', '5 / 98.00 %', '5 / 1.25 %', '98.74 %'])
        ->assertSeeInOrder(['$500', '8 / 99.15 %', '2 / 0.10 %', '99.90 %'])
        ->assertSeeInOrder(['$1,000', '9 / 99.25 %', '1 / 0.00 %', '100.00 %']);
});

it('accepts a free amount, a minimum position amount and a target', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('amount', '250.50')
        ->assertHasNoErrors()
        ->assertSee('At a copy amount of $250.50')
        ->assertSee('Skipped positions (3)')       // h, i, z
        ->set('minimumPositionAmount', '5')
        ->assertHasNoErrors()
        ->assertSee('Presets (minimum position $5.00)')
        ->set('targetCoverage', '99.5')
        ->assertHasNoErrors()
        ->assertSee('Minimum amount for 99.5 % target');
});

it('validates the inputs and shows no result for invalid values', function (string $property, string $value, string $message) {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set($property, $value)
        ->assertHasErrors($property)
        ->assertSee($message);
})->with([
    'too many decimals' => ['amount', '12.345', 'at most 2 decimals'],
    'not a number' => ['amount', 'abc', 'at most 2 decimals'],
    'zero amount' => ['amount', '0', 'greater than $0'],
    'empty amount' => ['amount', '', 'Enter a copy amount in USD.'],
    'zero minimum position' => ['minimumPositionAmount', '0.00', 'greater than $0'],
    'target above 100' => ['targetCoverage', '101', 'at most 100'],
    'target zero' => ['targetCoverage', '0', 'greater than 0'],
]);

it('warns when the amount is below the platform minimum', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('amount', '150')
        ->assertSee('The copy amount is below the $200.00 minimum copy amount of the platform.');
});

it('shows the 90/95/99/100% target matrix', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSeeInOrder(['90%', '$200.00', '$10.00', '98.74 %'])
        ->assertSeeInOrder(['95%', '$200.00', '$16.67', '98.74 %'])
        ->assertSeeInOrder(['99%', '$222.23', '$222.23', '99.19 %'])
        ->assertSeeInOrder(['100%*', '$1,000.00', '$1,000.00', '100.00 %'])
        ->assertSee('100% = every visible position; informational only');
});

it('shows targets as not reachable when no position has a positive weight', function () {
    $trader = availablePortfolioTrader();
    copySimulationStoredSnapshot([['z-1', '1', 0], ['z-2', '2', 0]], ['cash_weight_ppb' => 1_000_000_000], $trader);

    $component = Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSee('Not reachable — no position has a positive weight')
        ->assertSee('No position in the snapshot has a positive weight, so coverage cannot be calculated.');

    expect(substr_count($component->html(), 'Not reachable — no position has a positive weight'))->toBe(4);
});

it('marks an estimate and lists the data-quality warnings', function () {
    $trader = availablePortfolioTrader();
    copySimulationStoredSnapshot([['w-1', '1', 900_000_000]], ['cash_weight_ppb' => null], $trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSee('Estimate — see warnings')
        ->assertSee('The snapshot does not report a cash weight')
        ->assertSee('Unknown (cash not reported)')
        ->assertSee('Snapshot data is incomplete, so every figure is an estimate.');
});

it('saves the simulation as a reproducible copy_simulations row and lists it', function () {
    $trader = availablePortfolioTrader();
    $snapshot = copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('amount', '500')
        ->set('targetCoverage', '95')
        ->assertSee('No simulation saved for this trader yet.')
        ->callAction('saveSimulation')
        ->assertNotified('Simulation saved')
        ->assertSee('Saved simulations (latest 1)');

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSeeInOrder(['#'.$snapshot->id, '$500.00', '$1.00', '95 % → $200.00', '8 / 2', '99.90 %', CopySimulationSettings::METHODOLOGY_VERSION, 'Yes']);

    $simulation = CopySimulation::query()->sole();

    expect($simulation->trader_id)->toBe($trader->id)
        ->and($simulation->portfolio_snapshot_id)->toBe($snapshot->id)
        ->and($simulation->copy_amount_cents)->toBe(50_000)
        ->and($simulation->minimum_position_amount_cents)->toBe(100)
        ->and($simulation->target_coverage_ppb)->toBe(950_000_000)
        ->and($simulation->eligible_positions_count)->toBe(8)
        ->and($simulation->skipped_positions_count)->toBe(2)
        ->and($simulation->methodology_version)->toBe(CopySimulationSettings::METHODOLOGY_VERSION)
        ->and(app(SimulateCopyAmount::class)->reproduces($simulation))->toBeTrue()
        ->and($simulation->result)->toEqual(app(SimulateCopyAmount::class)->preview($snapshot, Money::fromCents(50_000), null, Percentage::fromPartsPerBillion(950_000_000)));

    Http::assertNothingSent();
});

it('does not save a simulation for invalid inputs', function () {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('amount', '12.345')
        ->callAction('saveSimulation')
        ->assertHasErrors('amount');

    expect(CopySimulation::count())->toBe(0);
});

it('flags a saved simulation of another methodology instead of recalculating it', function () {
    $trader = availablePortfolioTrader();
    $snapshot = copySimulationSnapshot($trader);
    CopySimulation::factory()->for($trader)->for($snapshot, 'snapshot')->create(['methodology_version' => 'copy-simulation-v0']);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertSeeInOrder(['copy-simulation-v0', 'Not checked (other methodology)']);
});

it('lists a saved simulation that recalculates out of range as not reproducible, never as an error page', function () {
    // A current-methodology row with a minimum position beyond the input cap
    // over a 1 ppb weight (e.g. written directly): recalculation is out of range.
    $trader = availablePortfolioTrader();
    $snapshot = copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0], $trader);
    CopySimulation::factory()->for($trader)->for($snapshot, 'snapshot')->create([
        'minimum_position_amount_cents' => 999_999_999_999_999,
        'methodology_version' => CopySimulationSettings::METHODOLOGY_VERSION,
    ]);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->assertOk()
        ->assertSeeInOrder([CopySimulationSettings::METHODOLOGY_VERSION, 'Not reproducible — out of range']);
});

it('rejects amounts above $10,000,000 and accepts the boundary', function (string $property) {
    $trader = availablePortfolioTrader();
    copySimulationSnapshot($trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set($property, '10000000')
        ->assertHasNoErrors($property)
        ->set($property, '10000000.01')
        ->assertHasErrors($property)
        ->assertSee('The amount must be at most $10,000,000.');
})->with(['amount', 'minimumPositionAmount']);

it('renders a huge minimum position over a 1 ppb weight as out of range, never as an error page', function () {
    $trader = availablePortfolioTrader();
    copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0], $trader);

    $component = Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('minimumPositionAmount', '9999999999999')
        ->assertOk()
        ->assertHasErrors('minimumPositionAmount')
        ->assertSee('The amount must be at most $10,000,000.')
        ->assertSee('a result for these inputs exceeds the representable amount, so nothing can be simulated or saved.')
        ->assertSeeInOrder(['99%', '$10,000,000,009,999.01'])
        ->assertSee('“Out of range” = the amount exceeds the representable range');

    // Three presets + the 100% target.
    expect(substr_count($component->html(), 'Out of range — practically unreachable'))->toBe(5);

    $component->callAction('saveSimulation')
        ->assertHasErrors('minimumPositionAmount');

    expect(CopySimulation::count())->toBe(0);
});

it('simulates and saves at the maximum minimum position over a 1 ppb weight', function () {
    // D-043: M = $10,000,000 ⇒ 100% target = 10¹⁸ cents, still representable.
    $trader = availablePortfolioTrader();
    copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0], $trader);

    Livewire::test(CopyAmountSimulator::class, ['record' => $trader])
        ->set('minimumPositionAmount', '10000000')
        ->set('targetCoverage', '100')
        ->assertHasNoErrors()
        ->assertDontSee('Out of range — practically unreachable')
        ->assertSee('$10,000,000,000,000,000.00')
        ->callAction('saveSimulation')
        ->assertNotified('Simulation saved');

    $simulation = CopySimulation::query()->sole();

    expect($simulation->minimum_target_amount_cents)->toBe(1_000_000_000_000_000_000)
        ->and(app(SimulateCopyAmount::class)->reproduces($simulation))->toBeTrue();
});

it('queues a portfolio sync from the header action, without any HTTP call', function () {
    Queue::fake();
    $trader = Trader::factory()->create();

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->callAction('syncPortfolio')
        ->assertNotified('Portfolio sync queued');

    Queue::assertPushed(SyncTraderPortfolioJob::class, fn (SyncTraderPortfolioJob $job) => $job->trader->is($trader));
    Http::assertNothingSent();
});

it('does not queue a portfolio sync when the eToro integration is disabled', function () {
    Queue::fake();
    config(['etoro.enabled' => false]);
    $trader = Trader::factory()->create();

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->callAction('syncPortfolio')
        ->assertNotified('eToro integration is disabled — nothing was queued.');

    Queue::assertNothingPushed();
});
