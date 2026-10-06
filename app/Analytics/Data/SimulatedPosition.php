<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use InvalidArgumentException;

/**
 * One position of a copy simulation, in the snapshot's original order.
 * estimatedAmount = floor(A × wᵢ) in cents (PROJECT.md §12.1
 * position_amountᵢ); null for a zero or negative weight, which receives
 * no allocation.
 */
final readonly class SimulatedPosition
{
    public function __construct(
        public int $index,
        public PositionCoverageOutcome $outcome,
        public ?Money $estimatedAmount,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException('SimulatedPosition index must not be negative.');
        }

        if (($estimatedAmount === null) === $outcome->weight->isPositive()) {
            throw new InvalidArgumentException('SimulatedPosition estimatedAmount must be present exactly for a positive weight.');
        }
    }
}
