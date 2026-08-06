<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * The coverage outcome for a single position. minimumCopyAmountForEligibility
 * is retained for a positive-weight position even when it is already
 * eligible — it is the breakpoint, not a "still needed" hint.
 */
final readonly class PositionCoverageOutcome
{
    public function __construct(
        public string $positionId,
        public Percentage $weight,
        public bool $eligible,
        public ?PositionSkipReason $reason,
        public ?Money $minimumCopyAmountForEligibility,
    ) {
        if (str_contains($positionId, "\0")) {
            throw new InvalidArgumentException('PositionCoverageOutcome positionId must not contain a NUL byte.');
        }

        if (trim($positionId, " \t\n\r\v\f") === '') {
            throw new InvalidArgumentException('PositionCoverageOutcome positionId must not be blank.');
        }

        if ($eligible && $reason !== null) {
            throw new InvalidArgumentException('PositionCoverageOutcome must not carry a reason when eligible.');
        }

        if ($eligible && ! $weight->isPositive()) {
            throw new InvalidArgumentException('PositionCoverageOutcome must not be eligible for a non-positive weight.');
        }

        if (! $eligible && $reason === null) {
            throw new InvalidArgumentException('PositionCoverageOutcome must carry a reason when not eligible.');
        }

        if ($weight->isPositive()) {
            if ($minimumCopyAmountForEligibility === null || ! $minimumCopyAmountForEligibility->isPositive()) {
                throw new InvalidArgumentException('PositionCoverageOutcome must carry a strictly positive minimumCopyAmountForEligibility for a positive weight.');
            }
        } elseif ($minimumCopyAmountForEligibility !== null) {
            throw new InvalidArgumentException('PositionCoverageOutcome must not carry a minimumCopyAmountForEligibility for a non-positive weight.');
        }

        if ($reason === PositionSkipReason::BelowMinimum && ! $weight->isPositive()) {
            throw new InvalidArgumentException('PositionSkipReason::BelowMinimum is only valid for a positive weight.');
        }

        if ($reason === PositionSkipReason::ZeroWeight && ! $weight->isZero()) {
            throw new InvalidArgumentException('PositionSkipReason::ZeroWeight is only valid for a zero weight.');
        }

        if ($reason === PositionSkipReason::NegativeWeight && ! $weight->isNegative()) {
            throw new InvalidArgumentException('PositionSkipReason::NegativeWeight is only valid for a negative weight.');
        }
    }
}
