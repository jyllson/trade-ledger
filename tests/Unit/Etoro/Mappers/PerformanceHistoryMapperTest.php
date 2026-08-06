<?php

use App\Etoro\Data\PerformanceHistory;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\PerformanceHistoryMapper;

/**
 * Loads a fresh decode of the synthetic fixture on every call — deliberately
 * avoids any Laravel helper (base_path()/app_path()) so this pure-PHP Unit
 * test suite never depends on the framework container being bootstrapped.
 *
 * @return array<string, mixed>
 */
function checkpointD1PerformanceHistoryFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/performance-history.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

function expectCheckpointD1PerformanceMappingException(callable $callback): EtoroMappingException
{
    try {
        $callback();
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException to be thrown.');
}

// --- Successful fixture mapping --------------------------------------------

it('maps the fixture successfully', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    expect($history)->toBeInstanceOf(PerformanceHistory::class);
});

it('maps 24 monthly and 3 yearly points', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    expect($history->monthly)->toHaveCount(24);
    expect($history->yearly)->toHaveCount(3);
});

it('maps the known first and last monthly timestamp/gain', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    $first = $history->monthly[0];
    $last = $history->monthly[23];

    expect($first->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2010-01-01T00:00:00Z');
    expect($first->gain)->toBe(0.021);
    expect($last->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2012-01-01T00:00:00Z');
    expect($last->gain)->toBe(0.019);
});

it('maps the known first and last yearly timestamp/gain', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    $first = $history->yearly[0];
    $last = $history->yearly[2];

    expect($first->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2010-01-01T00:00:00Z');
    expect($first->gain)->toBe(0.12);
    expect($last->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2012-01-01T00:00:00Z');
    expect($last->gain)->toBe(0.07);
});

it('preserves the existing deliberate monthly gap between 2010-06-01 and 2010-08-01', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    expect($history->monthly[5]->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2010-06-01T00:00:00Z');
    expect($history->monthly[6]->periodStartedAt->format('Y-m-d\TH:i:s\Z'))->toBe('2010-08-01T00:00:00Z');
});

it('produces ascending output for both monthly and yearly', function (): void {
    $history = (new PerformanceHistoryMapper)->map(checkpointD1PerformanceHistoryFixture());

    $monthlyTimestamps = array_map(fn ($p) => $p->periodStartedAt, $history->monthly);
    $yearlyTimestamps = array_map(fn ($p) => $p->periodStartedAt, $history->yearly);

    $sortedMonthly = $monthlyTimestamps;
    usort($sortedMonthly, fn ($a, $b) => $a <=> $b);
    $sortedYearly = $yearlyTimestamps;
    usort($sortedYearly, fn ($a, $b) => $a <=> $b);

    expect($monthlyTimestamps)->toBe($sortedMonthly);
    expect($yearlyTimestamps)->toBe($sortedYearly);
});

// --- Shape/type errors -------------------------------------------------

it('rejects a missing monthly key', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    unset($fixture['monthly']);

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('monthly');
});

it('rejects a missing yearly key', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    unset($fixture['yearly']);

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('yearly');
});

it('rejects a scalar monthly value', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'] = 'not-an-array';

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('monthly');
});

it('rejects a scalar yearly value', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['yearly'] = 'not-an-array';

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('yearly');
});

it('rejects an associative array for monthly', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'] = ['first' => $fixture['monthly'][0]];

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('monthly');
});

it('rejects an associative array for yearly', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['yearly'] = ['first' => $fixture['yearly'][0]];

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('yearly');
});

it('rejects a non-array monthly item', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0] = 'not-an-array';

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('monthly[0]');
});

it('rejects a missing timestamp field', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    unset($fixture['monthly'][0]['timestamp']);

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('monthly[0].timestamp');
});

it('rejects a missing gain field', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    unset($fixture['monthly'][0]['gain']);

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('monthly[0].gain');
});

it('rejects a timestamp of the wrong primitive type', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['timestamp'] = 123;

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('monthly[0].timestamp');
});

it('rejects a gain of the wrong primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['gain'] = $wrongValue;

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('monthly[0].gain');
})->with([
    'string' => ['not-a-number'],
    'bool' => [true],
    'array' => [['not', 'a', 'number']],
]);

it('rejects NAN, INF, and -INF gain as InvalidValue', function (float $raw): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['gain'] = $raw;

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('monthly[0].gain');
    expect($exception->getMessage())->not->toContain('NAN');
    expect($exception->getMessage())->not->toContain('INF');
})->with([
    'NAN' => [NAN],
    'INF' => [INF],
    '-INF' => [-INF],
]);

// --- Timestamp errors ----------------------------------------------------

it('rejects a malformed timestamp', function (string $raw): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['timestamp'] = $raw;

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MalformedTimestamp);
    expect($exception->fieldPath)->toBe('monthly[0].timestamp');
    expect($exception->getMessage())->not->toContain($raw);
})->with([
    'not a date at all' => ['not-a-date'],
    'missing trailing Z' => ['2010-01-01T00:00:00'],
    'timezone offset instead of Z' => ['2010-01-01T00:00:00+02:00'],
    'invalid calendar date' => ['2010-13-45T00:00:00Z'],
    'non-padded month/day' => ['2010-1-01T00:00:00Z'],
    'trailing garbage' => ['2010-01-01T00:00:00Zxyz'],
    'incomplete date' => ['2010-01-01'],
]);

it('rejects a timestamp containing a null byte as MalformedTimestamp, not a raw ValueError', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['timestamp'] = "2010-01-01T00:00:00Z\0sentinel-null-byte";

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MalformedTimestamp);
    expect($exception->fieldPath)->toBe('monthly[0].timestamp');
    expect($exception->getMessage())->not->toContain('sentinel-null-byte');
});

// --- Duplicate periods -----------------------------------------------------

it('rejects a duplicate monthly period, with fieldPath pointing to the second occurrence', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][3]['timestamp'] = $fixture['monthly'][0]['timestamp'];

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('monthly[3].timestamp');
    expect($exception->getMessage())->not->toContain($fixture['monthly'][0]['timestamp']);
});

it('rejects a duplicate yearly period, with fieldPath pointing to the second occurrence', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['yearly'][2]['timestamp'] = $fixture['yearly'][0]['timestamp'];

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('yearly[2].timestamp');
});

it('treats monthly and yearly duplicate detection independently', function (): void {
    // A duplicate confined to yearly must not affect a valid monthly series
    // and vice versa — proven by asserting the exception fieldPath is
    // scoped to the series that actually has the duplicate.
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['yearly'][1]['timestamp'] = $fixture['yearly'][0]['timestamp'];

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->fieldPath)->toStartWith('yearly[');
});

// --- Ordering ----------------------------------------------------------

it('accepts an unsorted but valid monthly series and normalizes it to ascending output', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $expectedTimestamps = array_column($fixture['monthly'], 'timestamp');
    $fixture['monthly'] = array_reverse($fixture['monthly']);

    $history = (new PerformanceHistoryMapper)->map($fixture);

    $mappedTimestamps = array_map(
        fn ($p) => $p->periodStartedAt->format('Y-m-d\TH:i:s\Z'),
        $history->monthly,
    );

    expect($mappedTimestamps)->toBe($expectedTimestamps);
});

it('accepts an unsorted but valid yearly series and normalizes it to ascending output', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $expectedTimestamps = array_column($fixture['yearly'], 'timestamp');
    $fixture['yearly'] = array_reverse($fixture['yearly']);

    $history = (new PerformanceHistoryMapper)->map($fixture);

    $mappedTimestamps = array_map(
        fn ($p) => $p->periodStartedAt->format('Y-m-d\TH:i:s\Z'),
        $history->yearly,
    );

    expect($mappedTimestamps)->toBe($expectedTimestamps);
});

it('does not mutate the input payload when normalizing unsorted input', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'] = array_reverse($fixture['monthly']);
    $originalJson = json_encode($fixture);

    (new PerformanceHistoryMapper)->map($fixture);

    expect(json_encode($fixture))->toBe($originalJson);
});

// --- Other -----------------------------------------------------------------

it('ignores an unknown top-level field', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['unknownTopLevelField'] = 'unexpected';

    expect(fn () => (new PerformanceHistoryMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('ignores an unknown per-item field', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['unknownField'] = 'unexpected';

    expect(fn () => (new PerformanceHistoryMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('does not mutate the input payload', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $originalJson = json_encode($fixture);

    (new PerformanceHistoryMapper)->map($fixture);

    expect(json_encode($fixture))->toBe($originalJson);
});

it('two calls with the same payload produce an identical result', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();

    $first = (new PerformanceHistoryMapper)->map($fixture);
    $second = (new PerformanceHistoryMapper)->map($fixture);

    $extract = fn (PerformanceHistory $h) => [
        array_map(fn ($p) => [$p->periodStartedAt->format('Y-m-d\TH:i:s\Z'), $p->gain], $h->monthly),
        array_map(fn ($p) => [$p->periodStartedAt->format('Y-m-d\TH:i:s\Z'), $p->gain], $h->yearly),
    ];

    expect($extract($second))->toBe($extract($first));
});

it('does not include a mutated sentinel value in mapper exception messages', function (): void {
    $fixture = checkpointD1PerformanceHistoryFixture();
    $fixture['monthly'][0]['gain'] = 'sentinel-marker-do-not-leak';

    $exception = expectCheckpointD1PerformanceMappingException(fn () => (new PerformanceHistoryMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain('sentinel-marker-do-not-leak');
});
