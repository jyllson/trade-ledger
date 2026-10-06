<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;

/**
 * Exact parsing of human-facing simulator inputs, shared by
 * `etoro:simulate-copy` and the Livewire CopyAmountSimulator — never
 * through a float (D-042).
 */
final class CopySimulationInput
{
    /**
     * Upper bound for the copy amount and the minimum position amount:
     * $10,000,000 = 10⁹ cents (D-043). Far above any realistic copy, and it
     * keeps every simulator figure representable for any positive weight
     * w ≥ 1 ppb: the breakpoint ceil(M × 10⁹ / w) ≤ 10¹⁸ cents and the
     * estimated amount floor(A × w / 10⁹) ≤ w, both below PHP_INT_MAX.
     */
    public const int MAXIMUM_AMOUNT_CENTS = 1_000_000_000;

    public static function maximumAmount(): Money
    {
        return Money::fromCents(self::MAXIMUM_AMOUNT_CENTS);
    }

    public static function exceedsMaximum(Money $amount): bool
    {
        return $amount->compareTo(self::maximumAmount()) > 0;
    }

    /**
     * "$10,000,000" — for validation messages.
     */
    public static function maximumAmountLabel(): string
    {
        return '$'.number_format(intdiv(self::MAXIMUM_AMOUNT_CENTS, 100));
    }

    /**
     * Decimal USD with at most 2 decimals ("500", "500.25") → cents; null
     * when malformed.
     */
    public static function parseUsd(string $raw): ?Money
    {
        if (preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $raw, $matches) !== 1) {
            return null;
        }

        return Money::fromCents((int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0'));
    }

    /**
     * Percentage points with up to 7 decimals, 0 < target <= 100 (D-023);
     * null otherwise.
     */
    public static function parseTargetPercent(string $raw): ?Percentage
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,7}))?$/', $raw, $matches) !== 1) {
            return null;
        }

        $ppb = (int) $matches[1] * 10_000_000 + (int) str_pad($matches[2] ?? '', 7, '0');

        return $ppb > 0 && $ppb <= 1_000_000_000 ? Percentage::fromPartsPerBillion($ppb) : null;
    }
}
