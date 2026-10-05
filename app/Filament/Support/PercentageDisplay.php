<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Analytics\ValueObjects\Percentage;

/**
 * Presentation-only formatting of exact Percentage values (decimal fraction
 * in ppb) as percent strings, rounded half away from zero with BCMath —
 * never through a float.
 */
final class PercentageDisplay
{
    public static function format(?Percentage $value, int $decimals = 2, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }

        // ppb → percent: ppb / 10_000_000
        $percent = bcdiv((string) $value->partsPerBillion(), '10000000', $decimals + 1);
        $half = bcdiv('5', bcpow('10', (string) ($decimals + 1)), $decimals + 1);
        $rounded = bccomp($percent, '0', $decimals + 1) < 0
            ? bcsub($percent, $half, $decimals)
            : bcadd($percent, $half, $decimals);

        if (bccomp($rounded, '0', $decimals) === 0) {
            $rounded = bcadd('0', '0', $decimals);
        }

        $sign = $signed && bccomp($rounded, '0', $decimals) > 0 ? '+' : '';

        return $sign.$rounded.' %';
    }

    /**
     * Percent as a plain number for chart axes (presentation only).
     */
    public static function chartValue(Percentage $value): float
    {
        return (float) bcdiv((string) $value->partsPerBillion(), '10000000', 4);
    }
}
