<?php

declare(strict_types=1);

namespace App\Application\Traders;

use RuntimeException;

/**
 * A stored simulation of another methodology version cannot be reproduced
 * with the current rules; it stays as stored (docs/DECISIONS.md D-042).
 */
final class UnsupportedCopySimulationMethodology extends RuntimeException
{
    public static function forVersion(string $version): self
    {
        return new self("Copy simulation methodology [{$version}] cannot be recalculated by [".CopySimulationSettings::METHODOLOGY_VERSION.'].');
    }
}
