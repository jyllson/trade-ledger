<?php

use App\Analytics\ValueObjects\Money;

it('stores a positive amount', function (): void {
    expect(Money::fromCents(500)->cents())->toBe(500);
});

it('stores a zero amount', function (): void {
    expect(Money::fromCents(0)->cents())->toBe(0);
});

it('stores a negative amount', function (): void {
    expect(Money::fromCents(-500)->cents())->toBe(-500);
});

it('preserves the exact cents value passed to fromCents', function (): void {
    expect(Money::fromCents(123_456_789)->cents())->toBe(123_456_789);
});

it('zero() returns a zero amount', function (): void {
    expect(Money::zero()->cents())->toBe(0);
    expect(Money::zero()->isZero())->toBeTrue();
});

it('reports sign correctly', function (): void {
    expect(Money::fromCents(1)->isPositive())->toBeTrue();
    expect(Money::fromCents(1)->isZero())->toBeFalse();
    expect(Money::fromCents(1)->isNegative())->toBeFalse();

    expect(Money::fromCents(0)->isPositive())->toBeFalse();
    expect(Money::fromCents(0)->isZero())->toBeTrue();
    expect(Money::fromCents(0)->isNegative())->toBeFalse();

    expect(Money::fromCents(-1)->isPositive())->toBeFalse();
    expect(Money::fromCents(-1)->isZero())->toBeFalse();
    expect(Money::fromCents(-1)->isNegative())->toBeTrue();
});

it('compares two amounts', function (): void {
    expect(Money::fromCents(100)->compareTo(Money::fromCents(200)))->toBe(-1);
    expect(Money::fromCents(200)->compareTo(Money::fromCents(200)))->toBe(0);
    expect(Money::fromCents(300)->compareTo(Money::fromCents(200)))->toBe(1);
});

it('is immutable', function (): void {
    $money = Money::fromCents(500);

    $property = new ReflectionProperty($money, 'cents');

    expect(fn () => $property->setValue($money, 999))->toThrow(Error::class);
    expect($money->cents())->toBe(500);
});
