<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\ValueObjects\Money;

/**
 * Defaults of the copy simulator (PROJECT.md §11 `copy_simulations`, §12).
 * Every stored simulation keeps the values it used, so changing a default
 * never changes an existing row.
 */
final class CopySimulationSettings
{
    /**
     * Bump when anything that shapes a stored `result` changes:
     * CopyCoverageCalculator, CopySimulationCalculator, the stored-snapshot
     * adapter, or SimulateCopyAmount's result document and texts.
     */
    public const string METHODOLOGY_VERSION = 'copy-simulation-v1';

    /** M = $1 (§12.1). */
    public const int DEFAULT_MINIMUM_POSITION_CENTS = 100;

    /** eToro minimum copy amount, $200 (§12.2 step 3). */
    public const int PLATFORM_MINIMUM_COPY_CENTS = 20_000;

    public static function defaultMinimumPositionAmount(): Money
    {
        return Money::fromCents(self::DEFAULT_MINIMUM_POSITION_CENTS);
    }

    public static function platformMinimumCopyAmount(): Money
    {
        return Money::fromCents(self::PLATFORM_MINIMUM_COPY_CENTS);
    }
}
