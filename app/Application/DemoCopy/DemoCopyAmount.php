<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Application\Traders\CopySimulationInput;
use App\Models\DemoCopyOperationType;

/**
 * Exact amount rules for demo copy registrations (D-050), in integer USD
 * cents — never a float.
 *
 * The eToro documentation only requires a non-zero amount (positive adds
 * funds, negative removes them) and documents no minimum or maximum, so
 * the platform minimum is left to the mandatory eToro pre-check. The
 * upper bound is the application-wide $10,000,000 (D-043).
 */
final class DemoCopyAmount
{
    public const int MAXIMUM_ABSOLUTE_CENTS = CopySimulationInput::MAXIMUM_AMOUNT_CENTS;

    /**
     * "500", "500.25", "-100" (adjust only) → cents; null when malformed.
     */
    public static function parseUsd(string $raw): ?int
    {
        if (preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', trim($raw), $matches) !== 1) {
            return null;
        }

        $cents = (int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    /**
     * @throws DemoCopyRefused
     */
    public static function assertValidFor(DemoCopyOperationType $intent, int $amountCents): void
    {
        if ($amountCents === 0) {
            throw DemoCopyRefused::because('The amount must not be zero.');
        }

        if (abs($amountCents) > self::MAXIMUM_ABSOLUTE_CENTS) {
            throw DemoCopyRefused::because('The amount must not exceed '.CopySimulationInput::maximumAmountLabel().'.');
        }

        if ($intent === DemoCopyOperationType::Start && $amountCents < 0) {
            throw DemoCopyRefused::because('A new copy needs a positive amount.');
        }

        if (! $intent->isRegistration()) {
            throw DemoCopyRefused::because('Only start and adjust operations carry an amount.');
        }
    }

    public static function format(?int $amountCents): string
    {
        if ($amountCents === null) {
            return '—';
        }

        return ($amountCents < 0 ? '-' : '').'$'.number_format(abs($amountCents) / 100, 2);
    }
}
