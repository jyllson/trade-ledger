<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Presentation-only formatting of exact integer cents and decimal strings —
 * never through a float.
 */
final class NumberDisplay
{
    /**
     * "$1,234.56" from integer cents; "—" for null.
     */
    public static function usd(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        $digits = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return sprintf(
            '%s$%s.%s',
            $cents < 0 ? '-' : '',
            number_format((int) substr($digits, 0, -2)),
            substr($digits, -2),
        );
    }

    /**
     * A non-negative decimal string (e.g. "1.500000000") rounded half up to
     * $decimals places with BCMath; "—" for null.
     *
     * @param  numeric-string|null  $value
     */
    public static function decimal(?string $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }

        $half = bcdiv('5', bcpow('10', (string) ($decimals + 1)), $decimals + 1);

        return bcadd($value, $half, $decimals);
    }
}
