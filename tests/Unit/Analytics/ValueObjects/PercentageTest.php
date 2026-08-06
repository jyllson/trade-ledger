<?php

use App\Analytics\ValueObjects\Percentage;

it('whole is exactly 1_000_000_000 ppb', function (): void {
    expect(Percentage::whole()->partsPerBillion())->toBe(1_000_000_000);
});

it('zero is 0 ppb', function (): void {
    expect(Percentage::zero()->partsPerBillion())->toBe(0);
});

it('fromPartsPerBillion preserves a signed value exactly', function (): void {
    expect(Percentage::fromPartsPerBillion(42)->partsPerBillion())->toBe(42);
    expect(Percentage::fromPartsPerBillion(-42)->partsPerBillion())->toBe(-42);
    expect(Percentage::fromPartsPerBillion(0)->partsPerBillion())->toBe(0);
});

it('converts eToro investmentPct percentage points to parts-per-billion', function (int|float $raw, int $expectedPpb): void {
    expect(Percentage::fromEtoroInvestmentPct($raw)->partsPerBillion())->toBe($expectedPpb);
})->with([
    '0.1%' => [0.1, 1_000_000],
    '0.2%' => [0.2, 2_000_000],
    '0.5%' => [0.5, 5_000_000],
    '0.13%' => [0.13, 1_300_000],
    '1 (int)' => [1, 10_000_000],
    '1.0 (float)' => [1.0, 10_000_000],
    '100%' => [100, 1_000_000_000],
]);

it('confirms 100% is not 100 * 1_000_000_000', function (): void {
    $hundredPercent = Percentage::fromEtoroInvestmentPct(100);

    expect($hundredPercent->partsPerBillion())->toBe(1_000_000_000);
    expect($hundredPercent->partsPerBillion())->not->toBe(100 * 1_000_000_000);
    expect($hundredPercent->partsPerBillion())->toBe(Percentage::whole()->partsPerBillion());
});

it('keeps a negative eToro percentage point value signed', function (): void {
    expect(Percentage::fromEtoroInvestmentPct(-0.1)->partsPerBillion())->toBe(-1_000_000);
});

it('rounds half up symmetrically at the boundary of the smallest supported scale', function (float $raw, int $expectedPpb): void {
    // The smallest supported ppb increment corresponds to 1e-7 percentage
    // points; half of that increment (5e-8) must round away from zero
    // under PHP_ROUND_HALF_UP, in both directions.
    expect(Percentage::fromEtoroInvestmentPct($raw)->partsPerBillion())->toBe($expectedPpb);
})->with([
    'positive half' => [0.00000005, 1],
    'negative half' => [-0.00000005, -1],
]);

it('rejects NAN', function (): void {
    expect(fn () => Percentage::fromEtoroInvestmentPct(NAN))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects positive and negative infinity', function (): void {
    expect(fn () => Percentage::fromEtoroInvestmentPct(INF))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => Percentage::fromEtoroInvestmentPct(-INF))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a raw value whose scaled result cannot fit in a PHP int', function (): void {
    expect(fn () => Percentage::fromEtoroInvestmentPct((float) PHP_INT_MAX))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects the exact upper-bound value that previously wrapped to PHP_INT_MIN', function (): void {
    // Regression test: (float) PHP_INT_MAX rounds up to 2^63 on 64-bit PHP
    // (PHP_INT_MAX itself, 2^63-1, has no exact double representation), so
    // the old bounds check (`$scaled > (float) PHP_INT_MAX`) let a $scaled
    // of exactly 2^63 through, and `(int) $scaled` then wrapped to
    // PHP_INT_MIN instead of throwing.
    $raw = ((float) PHP_INT_MAX) / 10_000_000;

    expect(fn () => Percentage::fromEtoroInvestmentPct($raw))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts the representable negative lower bound without wrapping to a positive value', function (): void {
    // -2^63 (PHP_INT_MIN) is itself a valid, representable int — this must
    // succeed and stay negative, not be misclassified as out-of-range or
    // wrap to a positive value the way the upper-bound bug did.
    $raw = ((float) PHP_INT_MIN) / 10_000_000;

    expect(Percentage::fromEtoroInvestmentPct($raw)->partsPerBillion())->toBe(PHP_INT_MIN);
});

it('rejects any type other than int or float', function (mixed $raw): void {
    expect(fn () => Percentage::fromEtoroInvestmentPct($raw))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'numeric string "1"' => ['1'],
    'numeric string "0.1"' => ['0.1'],
    'null' => [null],
    'bool true' => [true],
    'array' => [[0.1]],
]);

it('compares two percentages', function (): void {
    expect(Percentage::fromPartsPerBillion(100)->compareTo(Percentage::fromPartsPerBillion(200)))->toBe(-1);
    expect(Percentage::fromPartsPerBillion(200)->compareTo(Percentage::fromPartsPerBillion(200)))->toBe(0);
    expect(Percentage::fromPartsPerBillion(300)->compareTo(Percentage::fromPartsPerBillion(200)))->toBe(1);
});

it('reports sign correctly', function (): void {
    expect(Percentage::fromPartsPerBillion(1)->isPositive())->toBeTrue();
    expect(Percentage::fromPartsPerBillion(1)->isZero())->toBeFalse();
    expect(Percentage::fromPartsPerBillion(1)->isNegative())->toBeFalse();

    expect(Percentage::fromPartsPerBillion(0)->isPositive())->toBeFalse();
    expect(Percentage::fromPartsPerBillion(0)->isZero())->toBeTrue();
    expect(Percentage::fromPartsPerBillion(0)->isNegative())->toBeFalse();

    expect(Percentage::fromPartsPerBillion(-1)->isPositive())->toBeFalse();
    expect(Percentage::fromPartsPerBillion(-1)->isZero())->toBeFalse();
    expect(Percentage::fromPartsPerBillion(-1)->isNegative())->toBeTrue();
});

it('is immutable', function (): void {
    $percentage = Percentage::fromPartsPerBillion(500);

    $property = new ReflectionProperty($percentage, 'partsPerBillion');

    expect(fn () => $property->setValue($percentage, 999))->toThrow(Error::class);
    expect($percentage->partsPerBillion())->toBe(500);
});
