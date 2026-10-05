<?php

declare(strict_types=1);

namespace App\Analytics\Support;

use App\Analytics\ValueObjects\Percentage;

/**
 * Exact BCMath helpers for return arithmetic. Intermediate values are
 * decimal-fraction strings at SCALE digits; results are converted back to
 * Percentage (parts-per-billion) with round-half-up (away from zero for
 * negatives) only at the very end of a calculation.
 *
 * Never uses floats (PROJECT.md §9 — no binary floating point for
 * persisted/compared financial values).
 */
final class ReturnMath
{
    public const SCALE = 18;

    private const PPB = '1000000000';

    /**
     * @return numeric-string
     */
    public static function toFraction(Percentage $value): string
    {
        return bcdiv((string) $value->partsPerBillion(), self::PPB, self::SCALE);
    }

    /**
     * Rounds a decimal-fraction string half-up to the nearest ppb. Written
     * without bcround() so it also runs on PHP 8.3 (composer ^8.3).
     *
     * @param  numeric-string  $fraction
     */
    public static function toPercentage(string $fraction): Percentage
    {
        $scaled = bcmul($fraction, self::PPB, self::SCALE);
        $half = bccomp($scaled, '0', self::SCALE) < 0 ? '-0.5' : '0.5';

        return Percentage::fromPartsPerBillion((int) bcadd($scaled, $half, 0));
    }

    /**
     * Growth factor Π(1 + rₜ) as a decimal string. Empty input → '1'.
     *
     * @param  list<Percentage>  $returns
     * @return numeric-string
     */
    public static function growthFactor(array $returns): string
    {
        $factor = '1';

        foreach ($returns as $return) {
            $factor = bcmul($factor, bcadd('1', self::toFraction($return), self::SCALE), self::SCALE);
        }

        return $factor;
    }

    /**
     * Compounded return Π(1 + rₜ) − 1 (PROJECT.md §13.1).
     *
     * @param  list<Percentage>  $returns
     */
    public static function compoundedReturn(array $returns): Percentage
    {
        return self::toPercentage(bcsub(self::growthFactor($returns), '1', self::SCALE));
    }

    /**
     * Exact arithmetic mean as a decimal-fraction string. Caller guarantees
     * a non-empty list.
     *
     * @param  non-empty-list<Percentage>  $values
     * @return numeric-string
     */
    public static function mean(array $values): string
    {
        $sum = '0';

        foreach ($values as $value) {
            $sum = bcadd($sum, self::toFraction($value), self::SCALE);
        }

        return bcdiv($sum, (string) count($values), self::SCALE);
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    public static function sqrt(string $value): string
    {
        return bcsqrt($value, self::SCALE);
    }
}
