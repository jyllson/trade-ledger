<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\CopySimulationInput;

/**
 * Exact conversion between human-facing profile inputs (USD, percentage
 * points) and stored cents / ppb (D-048) — never through a float.
 */
final class AnalysisProfileInput
{
    /**
     * Decimal USD with at most 2 decimals → cents; null when malformed.
     */
    public static function parseUsd(string $raw): ?Money
    {
        return CopySimulationInput::parseUsd(trim($raw));
    }

    /**
     * Percentage points 0–100 with at most 7 decimals ("12.5" = 12.5%) →
     * ppb fraction; null when malformed or above 100.
     */
    public static function parsePercent(string $raw): ?Percentage
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,7}))?$/', trim($raw), $matches) !== 1) {
            return null;
        }

        $ppb = (int) $matches[1] * 10_000_000 + (int) str_pad($matches[2] ?? '', 7, '0');

        return $ppb <= AnalysisProfileSettings::WHOLE_PPB ? Percentage::fromPartsPerBillion($ppb) : null;
    }

    /**
     * Cents → "500" / "500.25" (the form's editable value).
     */
    public static function formatUsd(int $cents): string
    {
        $whole = (string) intdiv($cents, 100);

        return $cents % 100 === 0 ? $whole : sprintf('%s.%02d', $whole, $cents % 100);
    }

    /**
     * ppb → exact percentage points, trailing zeros trimmed ("95", "12.5").
     */
    public static function formatPercent(int $ppb): string
    {
        $percent = bcdiv((string) $ppb, '10000000', 7);

        return str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;
    }
}
