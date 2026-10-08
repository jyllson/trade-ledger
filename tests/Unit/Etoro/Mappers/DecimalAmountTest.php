<?php

use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\DecimalAmount;

it('converts JSON numbers to exact cents, rounding the sent decimal half away from zero', function (int|float $raw, int $cents) {
    expect(DecimalAmount::cents($raw, 'Test', 'field'))->toBe($cents);
})->with([
    'int' => [100000, 10_000_000],
    'one decimal' => [10000.5, 1_000_050],
    '1.005 rounds up, not down via binary' => [1.005, 101],
    '-1.005 rounds away from zero' => [-1.005, -101],
    'small negative' => [-0.004, 0],
    'sub-cent' => [0.125, 13],
    'zero float' => [0.0, 0],
]);

it('produces fixed-scale decimal strings', function () {
    expect(DecimalAmount::scaled(5.4839, 10, 'Test', 'field'))->toBe('5.4839000000')
        ->and(DecimalAmount::scaled(64250.5, 10, 'Test', 'field'))->toBe('64250.5000000000')
        ->and(DecimalAmount::scaled(1.0E-7, 10, 'Test', 'field'))->toBe('0.0000001000')
        ->and(DecimalAmount::scaled(100, 10, 'Test', 'field'))->toBe('100.0000000000');
});

it('rejects non-numbers and non-finite values', function (mixed $raw) {
    expect(fn () => DecimalAmount::cents($raw, 'Test', 'field'))->toThrow(EtoroMappingException::class);
})->with(['string' => ['12.5'], 'bool' => [true], 'null' => [null], 'nan' => [NAN], 'inf' => [INF], 'too big' => [1.0E+30]]);
