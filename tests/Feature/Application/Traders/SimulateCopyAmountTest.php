<?php

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\CopySimulationInput;
use App\Application\Traders\CopySimulationOutOfRange;
use App\Application\Traders\CopySimulationSettings;
use App\Application\Traders\CoverageTargetPreset;
use App\Application\Traders\SimulateCopyAmount;
use App\Application\Traders\UnsupportedCopySimulationMethodology;
use App\Models\CopySimulation;
use App\Models\Instrument;
use App\Models\PortfolioPosition;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CopySimulationFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @return array<string, array<string, mixed>> positions of a result keyed by position id
 */
function copySimulationPositionsById(CopySimulation $simulation): array
{
    return collect($simulation->result['positions'])->keyBy('position_id')->all();
}

it('stores a $200 simulation with exact counts, weights and per-position estimates', function () {
    $snapshot = copySimulationSnapshot();

    $simulation = app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000));

    // A = 20_000 cents: eligible ⇔ bpᵢ ≤ 20_000 → a..e (5); f, g, h, i
    // below minimum + z zero weight (5). Eligible 99.25% − 1.25% = 98%;
    // skipped 0.45 + 0.4 + 0.3 + 0.1 = 1.25%.
    // Coverage of P = 980 / 992.5 = 0.98740554156… → floor 987_405_541.
    expect($simulation->trader_id)->toBe($snapshot->trader_id)
        ->and($simulation->portfolio_snapshot_id)->toBe($snapshot->id)
        ->and($simulation->copy_amount_cents)->toBe(20_000)
        ->and($simulation->minimum_position_amount_cents)->toBe(100)
        ->and($simulation->platform_minimum_copy_amount_cents)->toBe(20_000)
        ->and($simulation->target_coverage_ppb)->toBeNull()
        ->and($simulation->minimum_target_amount_cents)->toBeNull()
        ->and($simulation->eligible_positions_count)->toBe(5)
        ->and($simulation->skipped_positions_count)->toBe(5)
        ->and($simulation->eligible_weight_ppb)->toBe(980_000_000)
        ->and($simulation->skipped_weight_ppb)->toBe(12_500_000)
        ->and($simulation->cash_weight_ppb)->toBe(7_500_000)
        ->and($simulation->methodology_version)->toBe('copy-simulation-v1')
        ->and($simulation->calculated_at)->not->toBeNull();

    $result = $simulation->fresh()->result;

    expect($result['summary'])->toBe([
        'eligible_positions_count' => 5,
        'skipped_positions_count' => 5,
        'total_positions_count' => 10,
        'eligible_weight_ppb' => 980_000_000,
        'skipped_weight_ppb' => 12_500_000,
        'visible_position_weight_ppb' => 992_500_000,
        'positive_position_weight_ppb' => 992_500_000,
        'coverage_of_positive_weight_ppb' => 987_405_541,
        'cash_weight_ppb' => 7_500_000,
        'unknown_weight_ppb' => 0,
        'below_platform_minimum' => false,
        'is_estimate' => false,
    ])
        ->and($result['warnings'])->toBe([])
        ->and($result['calculator_warnings'])->toBe(['observed_weight_not_whole'])
        ->and($result['target'])->toBeNull()
        ->and(array_column($result['positions'], 'position_id'))->toBe(['pos-a', 'pos-b', 'pos-c', 'pos-d', 'pos-e', 'pos-f', 'pos-g', 'pos-h', 'pos-i', 'pos-z']);

    // Position a: 20_000 × 0.5 = 10_000 cents; f: 20_000 × 0.0045 = 90 cents.
    expect($result['positions'][0])->toBe([
        'index' => 0,
        'position_id' => 'pos-a',
        'instrument_id' => '1001',
        'weight_ppb' => 500_000_000,
        'estimated_amount_cents' => 10_000,
        'minimum_copy_amount_cents' => 200,
        'eligible' => true,
        'skip_reason' => null,
        'explanation' => null,
    ])
        ->and($result['positions'][5])->toBe([
            'index' => 5,
            'position_id' => 'pos-f',
            'instrument_id' => '1006',
            'weight_ppb' => 4_500_000,
            'estimated_amount_cents' => 90,
            'minimum_copy_amount_cents' => 22_223,
            'eligible' => false,
            'skip_reason' => 'below_minimum',
            'explanation' => 'At a copy amount of $200.00 this position (0.45% of the portfolio) would be about $0.90, below the $1.00 minimum position amount, so it is not copied. It is copied from a copy amount of $222.23.',
        ]);
});

it('explains every skipped position with a code and a readable reason', function () {
    $simulation = app(SimulateCopyAmount::class)->handle(copySimulationSnapshot(), Money::fromCents(20_000));

    $skipped = array_values(array_filter($simulation->result['positions'], fn (array $position): bool => ! $position['eligible']));

    // g: 20_000 × 0.004 = 80 cents, bp $250.00; h: 60 cents, bp $333.34;
    // i: 20 cents, bp $1,000.00.
    expect($skipped)->toHaveCount($simulation->skipped_positions_count)
        ->and(array_column($skipped, 'skip_reason', 'position_id'))->toBe([
            'pos-f' => 'below_minimum',
            'pos-g' => 'below_minimum',
            'pos-h' => 'below_minimum',
            'pos-i' => 'below_minimum',
            'pos-z' => 'zero_weight',
        ])
        ->and($skipped[1]['explanation'])->toBe('At a copy amount of $200.00 this position (0.4% of the portfolio) would be about $0.80, below the $1.00 minimum position amount, so it is not copied. It is copied from a copy amount of $250.00.')
        ->and($skipped[3]['explanation'])->toBe('At a copy amount of $200.00 this position (0.1% of the portfolio) would be about $0.20, below the $1.00 minimum position amount, so it is not copied. It is copied from a copy amount of $1,000.00.')
        ->and($skipped[4])->toMatchArray([
            'estimated_amount_cents' => null,
            'minimum_copy_amount_cents' => null,
            'explanation' => 'This position has a 0% weight in the snapshot, so no amount is allocated to it and it is not copied.',
        ]);
});

it('explains negative weights, duplicates, unmodeled entries and unknown cash as an estimate', function () {
    // n-1 60% (bp 167), n-2 −0.5%, n-1 again 30% (bp 334), n-3 0%;
    // socialTrades 2, cash unknown. At $200 both n-1 entries are eligible
    // (90%); n-2 and n-3 are skipped with no positive weight (0).
    $snapshot = copySimulationStoredSnapshot([
        ['n-1', '2001', 600_000_000],
        ['n-2', '2002', -5_000_000],
        ['n-1', '2003', 300_000_000],
        ['n-3', '2004', 0],
    ], ['cash_weight_ppb' => null, 'social_trades_count' => 2]);

    $simulation = app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000));
    $result = $simulation->result;

    expect($simulation->eligible_positions_count)->toBe(2)
        ->and($simulation->skipped_positions_count)->toBe(2)
        ->and($simulation->eligible_weight_ppb)->toBe(900_000_000)
        ->and($simulation->skipped_weight_ppb)->toBe(0)
        ->and($simulation->cash_weight_ppb)->toBeNull()
        ->and(array_column($result['positions'], 'eligible'))->toBe([true, false, true, false])
        ->and(array_column($result['positions'], 'skip_reason'))->toBe([null, 'negative_weight', null, 'zero_weight'])
        ->and($result['positions'][1]['explanation'])->toBe('This position has a negative weight (-0.5%) in the snapshot. That is invalid source data, so it is ignored and not copied.')
        ->and($result['summary']['visible_position_weight_ppb'])->toBe(895_000_000)
        ->and($result['summary']['positive_position_weight_ppb'])->toBe(900_000_000)
        ->and($result['summary']['coverage_of_positive_weight_ppb'])->toBe(1_000_000_000)
        ->and($result['summary']['cash_weight_ppb'])->toBeNull()
        ->and($result['summary']['unknown_weight_ppb'])->toBeNull()
        ->and($result['summary']['is_estimate'])->toBeTrue()
        ->and(array_column($result['warnings'], 'code'))->toBe([
            'duplicate_position_id',
            'negative_weight_ignored',
            'unmodeled_portfolio_entries_present',
            'cash_weight_unknown',
        ])
        ->and($result['calculator_warnings'])->toBe([
            'duplicate_position_id',
            'negative_weight_ignored',
            'observed_weight_not_whole',
            'unmodeled_portfolio_entries_present',
        ]);
});

it('reports unaccounted weight separately from cash', function () {
    // Positions 80% + cash 10% ⇒ unknown 10% (100_000_000 ppb).
    $snapshot = copySimulationStoredSnapshot([['u-1', '3001', 800_000_000]], ['cash_weight_ppb' => 100_000_000]);

    $result = app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(50_000))->result;

    expect($result['summary']['cash_weight_ppb'])->toBe(100_000_000)
        ->and($result['summary']['unknown_weight_ppb'])->toBe(100_000_000)
        ->and($result['summary']['is_estimate'])->toBeTrue()
        ->and($result['warnings'])->toBe([[
            'code' => 'unaccounted_weight',
            'message' => 'Positions and cash leave 10% of the portfolio unaccounted for, so the result is an estimate.',
        ]]);
});

it('flags a copy amount below the platform minimum without changing eligibility', function () {
    // A = $150: bp ≤ 15_000 → a..e still eligible.
    $result = app(SimulateCopyAmount::class)->handle(copySimulationSnapshot(), Money::fromCents(15_000))->result;

    expect($result['summary']['eligible_positions_count'])->toBe(5)
        ->and($result['summary']['below_platform_minimum'])->toBeTrue()
        ->and($result['summary']['is_estimate'])->toBeFalse()
        ->and($result['warnings'])->toBe([[
            'code' => 'copy_amount_below_platform_minimum',
            'message' => 'The copy amount is below the $200.00 minimum copy amount of the platform.',
        ]]);
});

it('stores the minimum amount for an optional target coverage', function () {
    // 99% of P: ceil(0.99 × 992_500_000) = 982_575_000. Cumulative weight by
    // breakpoint: 500, 800, 900, 960, 980, 984.5 (f, bp 22_223) ≥ 982.575
    // ⇒ $222.23 (> $200 platform minimum). Covered at 22_223: a..f = 984.5M
    // ⇒ achieved 984.5 / 992.5 = 0.99193954659… → 991_939_546.
    $simulation = app(SimulateCopyAmount::class)->handle(
        copySimulationSnapshot(),
        Money::fromCents(50_000),
        targetCoverage: CoverageTargetPreset::Percent99->coverage(),
    );

    expect($simulation->target_coverage_ppb)->toBe(990_000_000)
        ->and($simulation->minimum_target_amount_cents)->toBe(22_223)
        ->and($simulation->result['inputs']['target_coverage_ppb'])->toBe(990_000_000)
        ->and($simulation->result['target'])->toBe([
            'target_coverage_ppb' => 990_000_000,
            'is_reachable' => true,
            'is_informational' => false,
            'mathematical_minimum_amount_cents' => 22_223,
            'minimum_amount_cents' => 22_223,
            'achieved_coverage_ppb' => 991_939_546,
            'covered_weight_ppb' => 984_500_000,
        ]);
});

it('honours a custom minimum position amount', function () {
    // M = $5 ⇒ bpᵢ = ceil(500 × 10⁹ / wᵢ): a 1_000, b 1_667, c 5_000,
    // d 8_334, e 25_000 … At $200 only a..d are eligible (96%).
    $simulation = app(SimulateCopyAmount::class)->handle(copySimulationSnapshot(), Money::fromCents(20_000), Money::fromCents(500));

    expect($simulation->minimum_position_amount_cents)->toBe(500)
        ->and($simulation->eligible_positions_count)->toBe(4)
        ->and($simulation->eligible_weight_ppb)->toBe(960_000_000)
        ->and(copySimulationPositionsById($simulation)['pos-e']['explanation'])
        ->toBe('At a copy amount of $200.00 this position (2% of the portfolio) would be about $4.00, below the $5.00 minimum position amount, so it is not copied. It is copied from a copy amount of $250.00.');
});

it('simulates an empty snapshot without inventing coverage', function () {
    $snapshot = copySimulationStoredSnapshot([], ['cash_weight_ppb' => 1_000_000_000]);

    $simulation = app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000), targetCoverage: Percentage::fromPartsPerBillion(950_000_000));

    expect($simulation->eligible_positions_count)->toBe(0)
        ->and($simulation->skipped_positions_count)->toBe(0)
        ->and($simulation->minimum_target_amount_cents)->toBeNull()
        ->and($simulation->result['positions'])->toBe([])
        ->and($simulation->result['summary']['coverage_of_positive_weight_ppb'])->toBeNull()
        ->and($simulation->result['summary']['unknown_weight_ppb'])->toBe(0)
        ->and($simulation->result['summary']['is_estimate'])->toBeFalse()
        ->and(array_column($simulation->result['warnings'], 'code'))->toBe(['empty_snapshot'])
        ->and($simulation->result['target'])->toMatchArray([
            'is_reachable' => false,
            'minimum_amount_cents' => null,
            'achieved_coverage_ppb' => null,
        ]);
});

it('recalculates a stored simulation bit-identically from the stored snapshot', function () {
    $snapshot = copySimulationSnapshot();
    $simulateCopyAmount = app(SimulateCopyAmount::class);
    $simulation = $simulateCopyAmount->handle($snapshot, Money::fromCents(50_000), targetCoverage: CoverageTargetPreset::Percent95->coverage());

    // Things outside the snapshot content change: clock, instrument
    // metadata. Neither is part of the methodology.
    $this->travel(3)->days();
    PortfolioPosition::query()->update(['instrument_id' => Instrument::factory()->enriched()->create()->id]);

    $stored = CopySimulation::query()->findOrFail($simulation->id);
    $recalculated = $simulateCopyAmount->recalculate($stored);

    expect($recalculated)->toBe($stored->result)
        ->and(json_encode($recalculated, JSON_THROW_ON_ERROR))->toBe(json_encode($stored->result, JSON_THROW_ON_ERROR))
        ->and($stored->getRawOriginal('result'))->toBe(json_encode($recalculated, JSON_THROW_ON_ERROR))
        ->and($simulateCopyAmount->reproduces($stored))->toBeTrue();
});

it('compares object keys order-independently but values strictly', function () {
    $simulateCopyAmount = app(SimulateCopyAmount::class);
    $simulation = $simulateCopyAmount->handle(copySimulationSnapshot(), Money::fromCents(20_000));

    // MySQL's JSON type re-orders object keys on storage.
    $reordered = $simulation->result;
    krsort($reordered);
    $reordered['summary'] = array_reverse($reordered['summary'], true);
    $simulation->result = $reordered;

    expect($simulateCopyAmount->reproduces($simulation))->toBeTrue();

    // "20000" is not 20000; a swapped list order is not the same list.
    $tampered = $reordered;
    $tampered['inputs']['copy_amount_cents'] = '20000';
    $simulation->result = $tampered;
    expect($simulateCopyAmount->reproduces($simulation))->toBeFalse();

    $tampered = $reordered;
    $tampered['positions'] = array_reverse($tampered['positions']);
    $simulation->result = $tampered;
    expect($simulateCopyAmount->reproduces($simulation))->toBeFalse();
});

it('keeps rows of another methodology version and refuses to recalculate them', function () {
    $simulation = app(SimulateCopyAmount::class)->handle(copySimulationSnapshot(), Money::fromCents(20_000));
    $simulation->forceFill(['methodology_version' => 'copy-simulation-v0'])->save();
    $storedResult = $simulation->fresh()->result;

    expect(fn () => app(SimulateCopyAmount::class)->recalculate($simulation->fresh()))
        ->toThrow(UnsupportedCopySimulationMethodology::class, 'copy-simulation-v0');

    expect($simulation->fresh()->result)->toBe($storedResult)
        ->and(CopySimulation::count())->toBe(1);
});

it('appends a new row per simulation and keeps older ones', function () {
    $snapshot = copySimulationSnapshot();

    app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000));
    app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000));

    expect($snapshot->copySimulations()->count())->toBe(2)
        ->and($snapshot->trader->copySimulations()->count())->toBe(2);
});

it('uses the documented defaults', function () {
    expect(CopySimulationSettings::METHODOLOGY_VERSION)->toBe('copy-simulation-v1')
        ->and(CopySimulationSettings::defaultMinimumPositionAmount()->cents())->toBe(100)
        ->and(CopySimulationSettings::platformMinimumCopyAmount()->cents())->toBe(20_000);
});

it('deletes simulations together with their snapshot', function () {
    $snapshot = copySimulationSnapshot();
    app(SimulateCopyAmount::class)->handle($snapshot, Money::fromCents(20_000));

    $snapshot->delete();

    expect(CopySimulation::count())->toBe(0);
});

it('never reaches the eToro transport from the simulator path', function (string $path) {
    expect(file_get_contents(app_path($path)))
        ->not->toContain('EtoroClient')
        ->not->toContain('LivePortfolioMapper')
        ->not->toContain('Facades\\Http')
        ->not->toContain('(float)');
})->with([
    'Application/Traders/SimulateCopyAmount.php',
    'Application/Traders/BuildCopySimulationMatrix.php',
    'Application/Traders/StoredPortfolioCoverageAdapter.php',
    'Analytics/Calculators/CopySimulationCalculator.php',
]);

it('throws a controlled out-of-range error and stores nothing when a figure is not representable', function () {
    $snapshot = copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0]);
    $simulateCopyAmount = app(SimulateCopyAmount::class);
    $hugeMinimum = Money::fromCents(999_999_999_999_900);

    expect(fn () => $simulateCopyAmount->preview($snapshot, Money::fromCents(20_000), $hugeMinimum))->toThrow(CopySimulationOutOfRange::class)
        ->and(fn () => $simulateCopyAmount->handle($snapshot, Money::fromCents(20_000), $hugeMinimum))->toThrow(CopySimulationOutOfRange::class)
        ->and(CopySimulation::count())->toBe(0);
});

it('bounds the copy and minimum position amounts at $10,000,000', function () {
    expect(CopySimulationInput::MAXIMUM_AMOUNT_CENTS)->toBe(1_000_000_000)
        ->and(CopySimulationInput::maximumAmountLabel())->toBe('$10,000,000')
        ->and(CopySimulationInput::exceedsMaximum(CopySimulationInput::parseUsd('10000000') ?? Money::zero()))->toBeFalse()
        ->and(CopySimulationInput::exceedsMaximum(CopySimulationInput::parseUsd('10000000.01') ?? Money::zero()))->toBeTrue();
});
