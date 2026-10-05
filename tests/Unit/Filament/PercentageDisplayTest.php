<?php

declare(strict_types=1);

use App\Analytics\ValueObjects\Percentage;
use App\Filament\Support\PercentageDisplay;

it('formats ppb fractions as percent strings rounded half away from zero', function (?int $ppb, string $expected, bool $signed) {
    $value = $ppb === null ? null : Percentage::fromPartsPerBillion($ppb);

    expect(PercentageDisplay::format($value, signed: $signed))->toBe($expected);
})->with([
    [155_000_000, '15.50 %', false],
    [155_000_000, '+15.50 %', true],
    [-76_000_000, '-7.60 %', true],
    [125_000, '0.01 %', false],
    [124_999, '0.01 %', false],
    [-125_000, '-0.01 %', false],
    [49_999, '0.00 %', false],
    [-49_999, '0.00 %', true],
    [0, '0.00 %', true],
    [null, '—', false],
]);

it('returns a plain percent number for charts', function () {
    expect(PercentageDisplay::chartValue(Percentage::fromPartsPerBillion(1_155_000_000)))->toBe(115.5);
});
