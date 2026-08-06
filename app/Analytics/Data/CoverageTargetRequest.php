<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * targetCoverage is relative to the total positive observed weight of the
 * snapshot (sum of weight > 0 positions), not to the nominal 100 percentage
 * points — see App\Analytics\Calculators\CopyCoverageCalculator.
 */
final readonly class CoverageTargetRequest
{
    /**
     * @var list<PositionAllocation>
     */
    public array $positions;

    /**
     * @param  array<int, mixed>  $positions
     */
    public function __construct(
        array $positions,
        public Percentage $targetCoverage,
        public Money $minimumPositionAmount,
        public Money $platformMinimumCopyAmount,
        public int $unmodeledEntryCount = 0,
    ) {
        if (! array_is_list($positions)) {
            throw new InvalidArgumentException('CoverageTargetRequest positions must be a list.');
        }

        foreach ($positions as $position) {
            if (! $position instanceof PositionAllocation) {
                throw new InvalidArgumentException('CoverageTargetRequest positions must contain only PositionAllocation instances.');
            }
        }

        if ($targetCoverage->compareTo(Percentage::zero()) <= 0) {
            throw new InvalidArgumentException('CoverageTargetRequest targetCoverage must be strictly positive.');
        }

        if ($targetCoverage->compareTo(Percentage::whole()) > 0) {
            throw new InvalidArgumentException('CoverageTargetRequest targetCoverage must not exceed whole.');
        }

        if (! $minimumPositionAmount->isPositive()) {
            throw new InvalidArgumentException('CoverageTargetRequest minimumPositionAmount must be strictly positive.');
        }

        if ($platformMinimumCopyAmount->isNegative()) {
            throw new InvalidArgumentException('CoverageTargetRequest platformMinimumCopyAmount must not be negative.');
        }

        if ($unmodeledEntryCount < 0) {
            throw new InvalidArgumentException('CoverageTargetRequest unmodeledEntryCount must not be negative.');
        }

        $this->positions = $positions;
    }
}
