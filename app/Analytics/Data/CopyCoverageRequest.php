<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use InvalidArgumentException;

final readonly class CopyCoverageRequest
{
    /**
     * @var list<PositionAllocation>
     */
    public array $positions;

    /**
     * $positions is deliberately undocumented as `list<PositionAllocation>`
     * at the parameter level — it is untrusted input here, and the runtime
     * checks below are what actually establish that guarantee for the
     * $positions property, mirroring the same pattern used by the eToro
     * live-portfolio domain mapping.
     *
     * @param  array<int, mixed>  $positions
     */
    public function __construct(
        public Money $copyAmount,
        public Money $minimumPositionAmount,
        array $positions,
        public int $unmodeledEntryCount = 0,
    ) {
        if (! array_is_list($positions)) {
            throw new InvalidArgumentException('CopyCoverageRequest positions must be a list.');
        }

        foreach ($positions as $position) {
            if (! $position instanceof PositionAllocation) {
                throw new InvalidArgumentException('CopyCoverageRequest positions must contain only PositionAllocation instances.');
            }
        }

        if ($copyAmount->isNegative()) {
            throw new InvalidArgumentException('CopyCoverageRequest copyAmount must not be negative.');
        }

        if (! $minimumPositionAmount->isPositive()) {
            throw new InvalidArgumentException('CopyCoverageRequest minimumPositionAmount must be strictly positive.');
        }

        if ($unmodeledEntryCount < 0) {
            throw new InvalidArgumentException('CopyCoverageRequest unmodeledEntryCount must not be negative.');
        }

        $this->positions = $positions;
    }
}
