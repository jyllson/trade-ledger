<?php

declare(strict_types=1);

namespace App\Etoro\Mappers\Support;

use App\Etoro\Exceptions\EtoroMappingException;

/**
 * Exact conversion of eToro JSON numbers (int, or float after decoding)
 * into integer cents or fixed-scale decimal strings — never floats in
 * storage or hashes (docs/DECISIONS.md D-042, D-051).
 *
 * A float is first written in its shortest round-trip form (PHP's
 * `var_export`, e.g. 1.005 → "1.005", not 1.00499999…), then rounded half
 * away from zero with BCMath. The decimal the API sent is therefore what
 * gets rounded, not the binary approximation of it.
 */
final class DecimalAmount
{
    private const DECIMAL_PATTERN = '/^-?\d+(\.\d+)?$/D';

    private function __construct() {}

    public static function cents(mixed $raw, string $mapper, string $fieldPath): int
    {
        $rounded = self::round(bcmul(self::decimal($raw, $mapper, $fieldPath), '100', 20), 0);

        if (bccomp($rounded, (string) PHP_INT_MAX, 0) > 0 || bccomp($rounded, (string) PHP_INT_MIN, 0) < 0) {
            throw EtoroMappingException::invalidValue($mapper, $fieldPath);
        }

        return (int) $rounded;
    }

    public static function scaled(mixed $raw, int $scale, string $mapper, string $fieldPath): string
    {
        return self::round(self::decimal($raw, $mapper, $fieldPath), $scale);
    }

    /**
     * @return numeric-string
     */
    private static function decimal(mixed $raw, string $mapper, string $fieldPath): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (! is_float($raw)) {
            throw EtoroMappingException::invalidPrimitiveType($mapper, $fieldPath, 'int|float', get_debug_type($raw));
        }

        if (is_nan($raw) || is_infinite($raw)) {
            throw EtoroMappingException::invalidValue($mapper, $fieldPath);
        }

        $shortest = var_export($raw, true);
        $decimal = preg_match(self::DECIMAL_PATTERN, $shortest) === 1 ? $shortest : sprintf('%.20F', $raw);

        if (! is_numeric($decimal)) {
            throw EtoroMappingException::invalidValue($mapper, $fieldPath);
        }

        return $decimal;
    }

    /**
     * Half away from zero; bcadd() truncates to the requested scale.
     *
     * @param  numeric-string  $decimal
     * @return numeric-string
     */
    private static function round(string $decimal, int $scale): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);

        return bcadd($decimal, bccomp($decimal, '0', 20) < 0 ? bcsub('0', $half, $scale + 1) : $half, $scale);
    }
}
