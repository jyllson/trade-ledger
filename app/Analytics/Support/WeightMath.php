<?php

declare(strict_types=1);

namespace App\Analytics\Support;

use App\Analytics\ValueObjects\Percentage;

/**
 * Exact BCMath ratios of non-negative ppb integer strings for the exposure
 * calculators (D-041). Rounds half-up only once, at the very end; never
 * uses floats.
 */
final class WeightMath
{
    public const DECIMAL_PLACES = 9;

    /**
     * numerator / denominator as a Percentage (ppb), rounded half-up.
     * Caller guarantees a positive denominator.
     *
     * @param  numeric-string  $numerator
     * @param  numeric-string  $denominator
     */
    public static function ratio(string $numerator, string $denominator): Percentage
    {
        return ReturnMath::toPercentage(bcdiv($numerator, $denominator, ReturnMath::SCALE));
    }

    /**
     * numerator / denominator as a plain decimal string with
     * DECIMAL_PLACES digits, rounded half-up (both operands non-negative,
     * denominator positive). For values that are not fractions of a whole,
     * e.g. an effective number of positions or an average leverage.
     *
     * @param  numeric-string  $numerator
     * @param  numeric-string  $denominator
     * @return numeric-string
     */
    public static function decimal(string $numerator, string $denominator): string
    {
        $exact = bcdiv($numerator, $denominator, ReturnMath::SCALE);

        return bcadd($exact, '0.'.str_repeat('0', self::DECIMAL_PLACES).'5', self::DECIMAL_PLACES);
    }
}
