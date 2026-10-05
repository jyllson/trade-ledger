<?php

declare(strict_types=1);

use App\Analytics\Support\ReturnMath;
use App\Analytics\ValueObjects\Percentage;

it('rounds a decimal fraction half-up to the nearest ppb, away from zero for negatives', function (string $fraction, int $expectedPpb) {
    expect(ReturnMath::toPercentage($fraction)->partsPerBillion())->toBe($expectedPpb);
})->with([
    ['0.0000000005', 1],
    ['0.00000000049', 0],
    ['-0.0000000005', -1],
    ['-0.00000000049', 0],
    ['0.155', 155_000_000],
]);

it('returns a zero compounded return for no periods', function () {
    expect(ReturnMath::compoundedReturn([])->partsPerBillion())->toBe(0);
});

it('converts ppb to an exact decimal fraction string', function () {
    expect(ReturnMath::toFraction(Percentage::fromPartsPerBillion(-12_500_000)))->toBe('-0.012500000000000000');
});
