<?php

use App\Etoro\Data\PerformanceHistory;
use App\Etoro\Data\PerformancePoint;

function checkpointD1Point(string $isoTimestamp, float $gain = 0.0): PerformancePoint
{
    return new PerformancePoint(new DateTimeImmutable($isoTimestamp), $gain);
}

// --- PerformancePoint ------------------------------------------------------

it('PerformancePoint accepts a positive gain', function (): void {
    $point = checkpointD1Point('2010-01-01T00:00:00Z', 0.021);

    expect($point->gain)->toBe(0.021);
});

it('PerformancePoint accepts a negative gain', function (): void {
    $point = checkpointD1Point('2010-01-01T00:00:00Z', -0.015);

    expect($point->gain)->toBe(-0.015);
});

it('PerformancePoint accepts a zero gain', function (): void {
    $point = checkpointD1Point('2010-01-01T00:00:00Z', 0.0);

    expect($point->gain)->toBe(0.0);
});

it('PerformancePoint rejects NAN', function (): void {
    expect(fn () => checkpointD1Point('2010-01-01T00:00:00Z', NAN))
        ->toThrow(InvalidArgumentException::class);
});

it('PerformancePoint rejects INF', function (): void {
    expect(fn () => checkpointD1Point('2010-01-01T00:00:00Z', INF))
        ->toThrow(InvalidArgumentException::class);
});

it('PerformancePoint rejects -INF', function (): void {
    expect(fn () => checkpointD1Point('2010-01-01T00:00:00Z', -INF))
        ->toThrow(InvalidArgumentException::class);
});

it('PerformancePoint is immutable', function (): void {
    $point = checkpointD1Point('2010-01-01T00:00:00Z', 0.02);

    $property = new ReflectionProperty($point, 'gain');

    expect(fn () => $property->setValue($point, 0.5))->toThrow(Error::class);
    expect($point->gain)->toBe(0.02);
});

// --- PerformanceHistory ------------------------------------------------------

it('PerformanceHistory accepts valid ascending monthly and yearly lists', function (): void {
    $history = new PerformanceHistory(
        monthly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-02-01T00:00:00Z')],
        yearly: [checkpointD1Point('2010-01-01T00:00:00Z')],
    );

    expect($history->monthly)->toHaveCount(2);
    expect($history->yearly)->toHaveCount(1);
});

it('PerformanceHistory accepts empty monthly and yearly lists', function (): void {
    $history = new PerformanceHistory(monthly: [], yearly: []);

    expect($history->monthly)->toBe([]);
    expect($history->yearly)->toBe([]);
});

it('PerformanceHistory rejects an associative array for monthly', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: ['first' => checkpointD1Point('2010-01-01T00:00:00Z')],
        yearly: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects an associative array for yearly', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [],
        yearly: ['first' => checkpointD1Point('2010-01-01T00:00:00Z')],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects a monthly element of the wrong type', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: ['not a point'],
        yearly: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects a yearly element of the wrong type', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [],
        yearly: ['not a point'],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects an unsorted monthly list passed directly', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [checkpointD1Point('2010-02-01T00:00:00Z'), checkpointD1Point('2010-01-01T00:00:00Z')],
        yearly: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects an unsorted yearly list passed directly', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [],
        yearly: [checkpointD1Point('2011-01-01T00:00:00Z'), checkpointD1Point('2010-01-01T00:00:00Z')],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects a duplicate monthly period', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-01-01T00:00:00Z')],
        yearly: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory rejects a duplicate yearly period', function (): void {
    expect(fn () => new PerformanceHistory(
        monthly: [],
        yearly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-01-01T00:00:00Z')],
    ))->toThrow(InvalidArgumentException::class);
});

it('PerformanceHistory allows gaps between periods', function (): void {
    $history = new PerformanceHistory(
        monthly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-08-01T00:00:00Z')],
        yearly: [],
    );

    expect($history->monthly)->toHaveCount(2);
});

it('PerformanceHistory treats monthly and yearly independently', function (): void {
    // An invalid (unsorted) yearly list must not be masked or fixed by a
    // valid monthly list, and vice versa.
    expect(fn () => new PerformanceHistory(
        monthly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-02-01T00:00:00Z')],
        yearly: [checkpointD1Point('2011-01-01T00:00:00Z'), checkpointD1Point('2010-01-01T00:00:00Z')],
    ))->toThrow(InvalidArgumentException::class);

    $history = new PerformanceHistory(
        monthly: [checkpointD1Point('2010-01-01T00:00:00Z'), checkpointD1Point('2010-02-01T00:00:00Z')],
        yearly: [checkpointD1Point('2010-01-01T00:00:00Z')],
    );

    expect($history->monthly)->toHaveCount(2);
    expect($history->yearly)->toHaveCount(1);
});

it('PerformanceHistory is immutable', function (): void {
    $history = new PerformanceHistory(monthly: [], yearly: []);

    $property = new ReflectionProperty($history, 'monthly');

    expect(fn () => $property->setValue($history, [checkpointD1Point('2010-01-01T00:00:00Z')]))->toThrow(Error::class);
});
