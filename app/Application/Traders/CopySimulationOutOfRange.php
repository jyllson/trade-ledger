<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Exceptions\CoverageCalculationException;
use RuntimeException;

/**
 * A simulator figure for these inputs and this snapshot (e.g. the copy
 * amount from which a 1 ppb position is copied with a huge minimum position
 * amount) does not fit the representable money range — practically
 * unreachable. Nothing is shown as a number and nothing is stored
 * (docs/DECISIONS.md D-043).
 */
final class CopySimulationOutOfRange extends RuntimeException
{
    public static function from(CoverageCalculationException $previous): self
    {
        return new self('The copy simulation result is outside the representable range (practically unreachable).', previous: $previous);
    }
}
