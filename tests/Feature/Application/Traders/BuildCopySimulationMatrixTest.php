<?php

use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\ValueObjects\Money;
use App\Application\Traders\BuildCopySimulationMatrix;
use App\Application\Traders\CopyAmountPreset;
use App\Application\Traders\CopySimulationInput;
use App\Application\Traders\CopySimulationOutOfRange;
use App\Application\Traders\CoverageTargetPreset;
use App\Models\CopySimulation;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CopySimulationFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

it('defines the $200/$500/$1,000 presets and the 90/95/99/100% targets in one place', function () {
    expect(array_map(fn (CopyAmountPreset $preset): array => [$preset->label(), $preset->amount()->cents()], CopyAmountPreset::cases()))
        ->toBe([['$200', 20_000], ['$500', 50_000], ['$1,000', 100_000]])
        ->and(array_map(fn (CoverageTargetPreset $target): array => [$target->label(), $target->coverage()->partsPerBillion(), $target->isInformational()], CoverageTargetPreset::cases()))
        ->toBe([
            ['90%', 900_000_000, false],
            ['95%', 950_000_000, false],
            ['99%', 990_000_000, false],
            ['100%', 1_000_000_000, true],
        ]);
});

it('builds presets × eligible/skipped/coverage from the stored snapshot', function () {
    // See CopySimulationFixtures.php; P = 992_500_000.
    //  $200:   bp ≤ 20_000  → a..e eligible (5), f g h i + z skipped (5),
    //          eligible 980M, skipped 12.5M, 980/992.5 → 987_405_541
    //  $500:   bp ≤ 50_000  → a..h eligible (8), i + z skipped (2),
    //          eligible 991.5M, skipped 1M, 991.5/992.5 → 998_992_443
    //  $1,000: bp ≤ 100_000 → a..i eligible (9), z skipped (1),
    //          eligible 992.5M, skipped 0, coverage 100%
    $matrix = app(BuildCopySimulationMatrix::class)->handle(copySimulationSnapshot());

    $rows = array_map(fn (CopySimulationResult $result): array => [
        $result->coverage->eligibleCount,
        $result->coverage->skippedCount,
        $result->coverage->coveredWeight->partsPerBillion(),
        $result->coverage->skippedWeight->partsPerBillion(),
        $result->coverageOfPositiveWeight?->partsPerBillion(),
    ], $matrix->presets);

    expect($rows)->toBe([
        20_000 => [5, 5, 980_000_000, 12_500_000, 987_405_541],
        50_000 => [8, 2, 991_500_000, 1_000_000, 998_992_443],
        100_000 => [9, 1, 992_500_000, 0, 1_000_000_000],
    ])
        ->and($matrix->preset(CopyAmountPreset::Usd500)->copyAmount->cents())->toBe(50_000)
        ->and($matrix->minimumPositionAmount->cents())->toBe(100)
        ->and($matrix->platformMinimumCopyAmount->cents())->toBe(20_000)
        ->and($matrix->warnings)->toBe([])
        ->and($matrix->isEstimate)->toBeFalse()
        ->and(CopySimulation::count())->toBe(0);
});

it('builds targets × minimum amount per §12.2 and §12.3', function () {
    // Cumulative weight by breakpoint: a 500 (200), b 800 (334),
    // c 900 (1_000), d 960 (1_667), e 980 (5_000), f 984.5 (22_223),
    // g 988.5 (25_000), h 991.5 (33_334), i 992.5 (100_000) [M ppb (cents)].
    //  90%:  ceil(0.90 × 992.5M) = 893.25M  → c, math $10.00 → $200 floor;
    //        covered at $200 = 980M → 987_405_541
    //  95%:  942.875M → d, math $16.67 → $200; same coverage
    //  99%:  982.575M → f, math = effective $222.23; 984.5M → 991_939_546
    //  100%: 992.5M → i: §12.3 max($200, ceil($1 / 0.001)) = $1,000.00
    $matrix = app(BuildCopySimulationMatrix::class)->handle(copySimulationSnapshot());

    $rows = array_map(fn (CoverageTargetResult $target): array => [
        $target->mathematicalMinimumCopyAmount?->cents(),
        $target->effectiveMinimumCopyAmount?->cents(),
        $target->achievedRatio?->partsPerBillion(),
    ], $matrix->targets);

    expect($rows)->toBe([
        900_000_000 => [1_000, 20_000, 987_405_541],
        950_000_000 => [1_667, 20_000, 987_405_541],
        990_000_000 => [22_223, 22_223, 991_939_546],
        1_000_000_000 => [100_000, 100_000, 1_000_000_000],
    ])
        ->and($matrix->target(CoverageTargetPreset::Percent100)->coveredRawWeight?->partsPerBillion())->toBe(992_500_000);
});

it('reports the 100% target for a tiny position however large it is', function () {
    // 1 ppb position: §12.3 ceil(100 × 10⁹ / 1) = 10¹¹ cents ($1 billion) —
    // informational, never capped.
    $snapshot = copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0]);

    $matrix = app(BuildCopySimulationMatrix::class)->handle($snapshot);

    expect($matrix->target(CoverageTargetPreset::Percent100)->effectiveMinimumCopyAmount?->cents())->toBe(100_000_000_000)
        ->and($matrix->target(CoverageTargetPreset::Percent99)->effectiveMinimumCopyAmount?->cents())->toBe(20_000)
        ->and(CoverageTargetPreset::Percent100->isInformational())->toBeTrue();
});

it('returns no target amount when no position has a positive weight', function () {
    // Unreachable at any amount: every target is null (N/A), never 0 or a cap.
    $snapshot = copySimulationStoredSnapshot([['z-1', '1', 0], ['z-2', '2', 0]], ['cash_weight_ppb' => 1_000_000_000]);

    $matrix = app(BuildCopySimulationMatrix::class)->handle($snapshot);

    foreach (CoverageTargetPreset::cases() as $target) {
        expect($matrix->target($target)->effectiveMinimumCopyAmount)->toBeNull()
            ->and($matrix->target($target)->achievedRatio)->toBeNull();
    }

    expect($matrix->preset(CopyAmountPreset::Usd1000)->coverage->skippedCount)->toBe(2)
        ->and($matrix->preset(CopyAmountPreset::Usd1000)->coverageOfPositiveWeight)->toBeNull()
        ->and($matrix->warnings)->toBe([CopySimulationWarning::NoPositiveWeight])
        ->and($matrix->isEstimate)->toBeFalse();
});

it('carries the snapshot data-quality warnings and estimate flag', function () {
    $snapshot = copySimulationStoredSnapshot([['w-1', '1', 900_000_000]], ['cash_weight_ppb' => null]);

    $matrix = app(BuildCopySimulationMatrix::class)->handle($snapshot);

    expect($matrix->warnings)->toBe([CopySimulationWarning::CashWeightUnknown])
        ->and($matrix->isEstimate)->toBeTrue()
        ->and($matrix->portfolioSnapshotId)->toBe($snapshot->id);
});

it('marks figures outside the representable range as out of range instead of throwing', function () {
    // M = $9,999,999,999,999 over a 1 ppb weight: ceil(M × 10⁹ / 1) cents ≈
    // 10²⁴ > PHP_INT_MAX. Every preset computes that breakpoint, the 100%
    // target needs it; 90/95/99% only need the 999_999_999 ppb position.
    $snapshot = copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0]);

    $matrix = app(BuildCopySimulationMatrix::class)->handle($snapshot, Money::fromCents(999_999_999_999_900));

    expect($matrix->isOutOfRange())->toBeTrue()
        ->and(array_map(fn (CopyAmountPreset $preset): bool => $matrix->presetIsOutOfRange($preset), CopyAmountPreset::cases()))->toBe([true, true, true])
        ->and(array_map(fn (CoverageTargetPreset $target): bool => $matrix->targetIsOutOfRange($target), CoverageTargetPreset::cases()))->toBe([false, false, false, true])
        ->and($matrix->target(CoverageTargetPreset::Percent99)->effectiveMinimumCopyAmount?->cents())->toBe(1_000_000_000_999_901)
        ->and($matrix->warnings)->toBe([])
        ->and(fn () => $matrix->target(CoverageTargetPreset::Percent100))->toThrow(CopySimulationOutOfRange::class);
});

it('keeps every figure representable at the maximum input amount, even for a 1 ppb weight', function () {
    // D-043: M = $10,000,000 = 10⁹ cents ⇒ ceil(10⁹ × 10⁹ / 1) = 10¹⁸ cents.
    $snapshot = copySimulationStoredSnapshot([['big', '1', 999_999_999], ['tiny', '2', 1]], ['cash_weight_ppb' => 0]);

    $matrix = app(BuildCopySimulationMatrix::class)->handle($snapshot, CopySimulationInput::maximumAmount());

    expect($matrix->isOutOfRange())->toBeFalse()
        ->and($matrix->target(CoverageTargetPreset::Percent100)->effectiveMinimumCopyAmount?->cents())->toBe(1_000_000_000_000_000_000)
        ->and($matrix->preset(CopyAmountPreset::Usd200)->coverage->eligibleCount)->toBe(0);
});
