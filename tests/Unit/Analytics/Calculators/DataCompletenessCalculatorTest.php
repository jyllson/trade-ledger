<?php

declare(strict_types=1);

use App\Analytics\Calculators\DataCompletenessCalculator;
use App\Analytics\Data\CompletenessState;
use App\Analytics\Data\CompletenessUnsupportedReason;

/**
 * The four sources the application collects today; the other four §13.8
 * checks are not supported (D-047).
 *
 * @param  array<string, CompletenessState>  $overrides
 * @return array<string, CompletenessState>
 */
function collectableStates(array $overrides = []): array
{
    return [
        'profile' => CompletenessState::Present,
        'monthly_history_24' => CompletenessState::Present,
        'daily_data' => CompletenessState::Present,
        'live_portfolio' => CompletenessState::Present,
        ...$overrides,
    ];
}

/**
 * @return array<string, CompletenessUnsupportedReason>
 */
function notSupportedChecks(): array
{
    return array_fill_keys(
        ['asset_history', 'exposure_history', 'trade_info', 'copier_history'],
        CompletenessUnsupportedReason::NotCollectedByApplication,
    );
}

it('scores a trader with every collectable source fresh as 100%, with the unsupported checks listed apart', function () {
    $result = (new DataCompletenessCalculator)->calculate(collectableStates(), notSupportedChecks());

    expect($result->methodologyVersion)->toBe('completeness-v1')
        ->and($result->score->partsPerBillion())->toBe(1_000_000_000)
        ->and($result->presentCount)->toBe(4)
        ->and($result->collectableCount)->toBe(4)
        ->and($result->totalCount)->toBe(8)
        ->and(array_keys($result->checks))->toBe(['profile', 'monthly_history_24', 'daily_data', 'live_portfolio'])
        ->and($result->notSupported)->toBe(notSupportedChecks());
});

it('scores present checks over the collectable checks only, each with equal weight', function () {
    // 3 present of 4 collectable → 0.75 (the 4 unsupported checks are not in the denominator)
    $result = (new DataCompletenessCalculator)->calculate(
        collectableStates(['daily_data' => CompletenessState::Stale]),
        notSupportedChecks(),
    );

    expect($result->presentCount)->toBe(3)
        ->and($result->score->partsPerBillion())->toBe(750_000_000)
        ->and($result->checks['daily_data'])->toBe(CompletenessState::Stale);
});

it('counts stale and missing checks as zero, never hiding them', function () {
    $none = (new DataCompletenessCalculator)->calculate(
        array_map(fn () => CompletenessState::Missing, collectableStates(['live_portfolio' => CompletenessState::Stale])),
        notSupportedChecks(),
    );

    expect($none->score->partsPerBillion())->toBe(0)
        ->and($none->checks)->toHaveCount(4)
        ->and($none->notSupported)->toHaveCount(4);
});

it('weights each collectable check equally whatever their number', function () {
    // every check collectable: 1 present / 8 = 0.125
    $states = array_map(fn () => CompletenessState::Missing, [...collectableStates(), ...notSupportedChecks()]);
    $result = (new DataCompletenessCalculator)->calculate([...$states, 'profile' => CompletenessState::Present], []);

    expect($result->score->partsPerBillion())->toBe(125_000_000)
        ->and($result->collectableCount)->toBe(8)
        ->and($result->notSupported)->toBe([]);
});

it('rejects a check that is missing, listed twice, or unknown, and an empty collectable set', function () {
    $collectable = collectableStates();
    unset($collectable['profile']);
    $calculator = new DataCompletenessCalculator;

    expect(fn () => $calculator->calculate($collectable, notSupportedChecks()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->calculate(collectableStates(['trade_info' => CompletenessState::Present]), notSupportedChecks()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->calculate([...collectableStates(), 'risk' => CompletenessState::Present], notSupportedChecks()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->calculate([], [
            ...notSupportedChecks(),
            ...array_fill_keys(array_keys(collectableStates()), CompletenessUnsupportedReason::NotCollectedByApplication),
        ]))->toThrow(InvalidArgumentException::class, 'At least one');
});
