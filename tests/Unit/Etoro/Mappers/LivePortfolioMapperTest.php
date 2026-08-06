<?php

use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\LivePortfolioMapper;

/**
 * Loads a fresh decode of the synthetic fixture on every call — deliberately
 * avoids any Laravel helper (base_path()/app_path()) so this pure-PHP Unit
 * test suite never depends on the framework container being bootstrapped.
 *
 * @return array<string, mixed>
 */
function checkpointBLivePortfolioFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/live-portfolio.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

function checkpointBLivePortfolioFieldToProperty(string $field): string
{
    return match ($field) {
        'openTimestamp' => 'openedAt',
        default => $field,
    };
}

function expectCheckpointBLivePortfolioMappingException(callable $callback): EtoroMappingException
{
    try {
        $callback();
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException to be thrown.');
}

// --- Successful mapping ------------------------------------------------

it('maps the fixture successfully', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect($portfolio)->toBeInstanceOf(LivePortfolio::class);
});

it('maps all 16 positions', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect($portfolio->positions)->toHaveCount(16);
});

it('preserves 6 unique instrumentId values', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    $uniqueInstruments = array_unique(array_map(
        fn (PortfolioPosition $position): string => $position->instrumentId,
        $portfolio->positions,
    ));

    expect($uniqueInstruments)->toHaveCount(6);
});

it('preserves position order', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    $ids = array_map(fn (PortfolioPosition $position): string => $position->positionId, $portfolio->positions);

    expect($ids)->toBe([
        '700001', '700002', '700003', '700004', '700005', '700006', '700007', '700008',
        '700009', '700010', '700011', '700012', '700013', '700014', '700015', '700016',
    ]);
});

it('normalizes an integer positionId and instrumentId to string', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect($portfolio->positions[0]->positionId)->toBeString()->toBe('700001');
    expect($portfolio->positions[0]->instrumentId)->toBeString()->toBe('500001');
});

it('converts investmentPct 0.1 to 1_000_000 ppb', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect($portfolio->positions[0]->weight->partsPerBillion())->toBe(1_000_000);
});

it('converts a bare JSON integer investmentPct of 1 to 10_000_000 ppb', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    // positions[5] (positionId 700006) has a bare JSON integer investmentPct: 1.
    expect($portfolio->positions[5]->positionId)->toBe('700006');
    expect($portfolio->positions[5]->weight->partsPerBillion())->toBe(10_000_000);
});

it('maps optional fields correctly for the first position', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());
    $first = $portfolio->positions[0];

    expect($first->isBuy)->toBeTrue();
    expect($first->leverage)->toBe(1);
    expect($first->takeProfitRate)->toBe(23.0);
    expect($first->stopLossRate)->toBe(18.0);
    expect($first->trailingStopLoss)->toBeFalse();
});

it('parses openedAt as a UTC DateTimeImmutable', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());
    $openedAt = $portfolio->positions[0]->openedAt;

    expect($openedAt)->toBeInstanceOf(DateTimeImmutable::class);
    expect($openedAt->getTimezone()->getName())->toBe('UTC');
    expect($openedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2012-01-10T09:15:00Z');
});

it('reports socialTradesCount=0 and hasUnmodeledSocialTrades=false for the base fixture', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect($portfolio->socialTradesCount)->toBe(0);
    expect($portfolio->hasUnmodeledSocialTrades())->toBeFalse();
});

it('reports a non-empty socialTrades count without parsing item content', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['socialTrades'] = [
        ['completelyUnmodeled' => 'shape', 'nested' => ['whatever' => true]],
        ['another' => 1],
    ];

    $portfolio = (new LivePortfolioMapper)->map($fixture);

    expect($portfolio->socialTradesCount)->toBe(2);
    expect($portfolio->hasUnmodeledSocialTrades())->toBeTrue();
});

it('does not expose netProfit or openRate on the mapped DTOs', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    expect(property_exists($portfolio->positions[0], 'netProfit'))->toBeFalse();
    expect(property_exists($portfolio->positions[0], 'openRate'))->toBeFalse();
});

it('keeps duplicate positionId values present without deduplication', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][1]['positionId'] = $fixture['positions'][0]['positionId'];

    $portfolio = (new LivePortfolioMapper)->map($fixture);

    expect($portfolio->positions)->toHaveCount(16);
    expect($portfolio->positions[0]->positionId)->toBe($portfolio->positions[1]->positionId);
});

it('does not aggregate multiple positions of the same instrument', function (): void {
    $portfolio = (new LivePortfolioMapper)->map(checkpointBLivePortfolioFixture());

    $instrument500001Positions = array_filter(
        $portfolio->positions,
        fn (PortfolioPosition $position): bool => $position->instrumentId === '500001',
    );

    expect($instrument500001Positions)->toHaveCount(5);
});

it('ignores an unknown top-level field', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['unknownTopLevelField'] = 'unexpected';

    expect(fn () => (new LivePortfolioMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('ignores an unknown position field', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['unknownPositionField'] = 'unexpected';

    expect(fn () => (new LivePortfolioMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('does not mutate the input payload', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $originalJson = json_encode($fixture);

    (new LivePortfolioMapper)->map($fixture);

    expect(json_encode($fixture))->toBe($originalJson);
});

// --- Optional fields: absent / null / wrong type ------------------------

it('maps an absent optional field to null', function (string $field): void {
    $fixture = checkpointBLivePortfolioFixture();
    unset($fixture['positions'][0][$field]);

    $portfolio = (new LivePortfolioMapper)->map($fixture);
    $property = checkpointBLivePortfolioFieldToProperty($field);

    expect($portfolio->positions[0]->$property)->toBeNull();
})->with(['openTimestamp', 'isBuy', 'leverage', 'takeProfitRate', 'stopLossRate', 'trailingStopLoss']);

it('maps an explicit null optional field to null', function (string $field): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0][$field] = null;

    $portfolio = (new LivePortfolioMapper)->map($fixture);
    $property = checkpointBLivePortfolioFieldToProperty($field);

    expect($portfolio->positions[0]->$property)->toBeNull();
})->with(['openTimestamp', 'isBuy', 'leverage', 'takeProfitRate', 'stopLossRate', 'trailingStopLoss']);

it('rejects an optional field of the wrong type', function (string $field, mixed $wrongValue): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0][$field] = $wrongValue;

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe("positions[0].{$field}");
})->with([
    'openTimestamp not a string' => ['openTimestamp', 123],
    'isBuy not a bool' => ['isBuy', 'true'],
    'leverage not an int' => ['leverage', 1.5],
    'takeProfitRate not numeric' => ['takeProfitRate', 'not-a-number'],
    'stopLossRate not numeric' => ['stopLossRate', ['not', 'a', 'number']],
    'trailingStopLoss not a bool' => ['trailingStopLoss', 1],
]);

// --- Top-level shape/required-field errors ------------------------------

it('rejects a missing positions key', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    unset($fixture['positions']);

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('positions');
});

it('rejects a missing socialTrades key', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    unset($fixture['socialTrades']);

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('socialTrades');
});

it('rejects positions of the wrong top-level type', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'] = 'not-an-array';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('positions');
});

it('rejects socialTrades of the wrong top-level type', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['socialTrades'] = 'not-an-array';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('socialTrades');
});

it('rejects an associative array instead of a list for positions', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'] = ['first' => $fixture['positions'][0]];

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('positions');
});

it('rejects a non-array position item', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0] = 'not-an-array';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('positions[0]');
});

// --- Required position fields -------------------------------------------

it('rejects a position missing a required key', function (string $missingKey): void {
    $fixture = checkpointBLivePortfolioFixture();
    unset($fixture['positions'][0][$missingKey]);

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe("positions[0].{$missingKey}");
})->with(['positionId', 'instrumentId', 'investmentPct']);

it('rejects an invalid positionId type', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['positionId'] = 1.5;

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('positions[0].positionId');
});

it('rejects a blank positionId', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['positionId'] = '   ';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('positions[0].positionId');
});

it('rejects an invalid investmentPct type', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['investmentPct'] = '0.1';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('positions[0].investmentPct');
});

it('rejects NAN and INF investmentPct as InvalidValue', function (float $raw): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['investmentPct'] = $raw;

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('positions[0].investmentPct');
})->with([
    'NAN' => [NAN],
    'INF' => [INF],
    '-INF' => [-INF],
]);

// --- Timestamp parsing ---------------------------------------------------

it('rejects a malformed openTimestamp', function (string $raw): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['openTimestamp'] = $raw;

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MalformedTimestamp);
    expect($exception->fieldPath)->toBe('positions[0].openTimestamp');
    expect($exception->getMessage())->not->toContain($raw);
})->with([
    'not a date at all' => ['not-a-date'],
    'missing trailing Z' => ['2012-01-10T09:15:00'],
    'timezone offset instead of Z' => ['2012-01-10T09:15:00+02:00'],
    'invalid calendar date' => ['2012-13-45T09:15:00Z'],
    'non-padded month/day' => ['2012-1-10T09:15:00Z'],
    'trailing garbage' => ['2012-01-10T09:15:00Zxyz'],
]);

it('rejects an openTimestamp containing a null byte as MalformedTimestamp, not a raw ValueError', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['openTimestamp'] = "2012-01-10T09:15:00Z\0sentinel-null-byte";

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MalformedTimestamp);
    expect($exception->fieldPath)->toBe('positions[0].openTimestamp');
    expect($exception->getMessage())->not->toContain('sentinel-null-byte');
});

// --- Non-finite optional rate values -------------------------------------

it('rejects NAN and INF for optional rate fields as InvalidValue', function (string $field, float $raw): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0][$field] = $raw;

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe("positions[0].{$field}");
    // Textual literals, not a (string) cast of $raw — casting NAN/INF
    // itself triggers a PHP "coerced to string" warning.
    expect($exception->getMessage())->not->toContain('NAN');
    expect($exception->getMessage())->not->toContain('INF');
})->with([
    'takeProfitRate NAN' => ['takeProfitRate', NAN],
    'takeProfitRate INF' => ['takeProfitRate', INF],
    'takeProfitRate -INF' => ['takeProfitRate', -INF],
    'stopLossRate NAN' => ['stopLossRate', NAN],
    'stopLossRate INF' => ['stopLossRate', INF],
    'stopLossRate -INF' => ['stopLossRate', -INF],
]);

// --- List-shape edge cases ------------------------------------------------

it('rejects an associative socialTrades array as UnexpectedShape', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['socialTrades'] = ['first' => []];

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('socialTrades');
});

it('maps a minimal payload with empty positions and socialTrades lists', function (): void {
    $portfolio = (new LivePortfolioMapper)->map([
        'positions' => [],
        'socialTrades' => [],
    ]);

    expect($portfolio->positions)->toBe([]);
    expect($portfolio->socialTradesCount)->toBe(0);
    expect($portfolio->hasUnmodeledSocialTrades())->toBeFalse();
});

// --- Exception message sanitization --------------------------------------

it('does not include a mutated sentinel value in mapper exception messages', function (): void {
    $fixture = checkpointBLivePortfolioFixture();
    $fixture['positions'][0]['investmentPct'] = 'sentinel-marker-do-not-leak';

    $exception = expectCheckpointBLivePortfolioMappingException(fn () => (new LivePortfolioMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain('sentinel-marker-do-not-leak');
});
