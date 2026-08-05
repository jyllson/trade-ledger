<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * A single generic, source-neutral position allocation: an identifier and a
 * signed weight. Deliberately carries no instrumentId or any other source
 * concept — CopyCoverageCalculator only needs weight per position, not what
 * instrument that position is in.
 */
final readonly class PositionAllocation
{
    public function __construct(
        public string $positionId,
        public Percentage $weight,
    ) {
        if (str_contains($positionId, "\0")) {
            throw new InvalidArgumentException('PositionAllocation positionId must not contain a NUL byte.');
        }

        // Whitespace-only charlist, not trim()'s default charlist, which
        // also strips "\0" and would let a NUL-only string slip past the
        // check above undetected.
        if (trim($positionId, " \t\n\r\v\f") === '') {
            throw new InvalidArgumentException('PositionAllocation positionId must not be blank.');
        }
    }
}
