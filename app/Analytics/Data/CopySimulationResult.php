<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;

/**
 * Copy simulation of one amount over one portfolio (docs/DECISIONS.md
 * D-042). Weights in `coverage` are fractions of the WHOLE portfolio
 * (PROJECT.md §12.1 coverage(A) = Σ eligible wᵢ); coverageOfPositiveWeight
 * is the same coverage relative to the visible positive position weight —
 * the basis of target coverage (D-022).
 */
final readonly class CopySimulationResult
{
    /**
     * @param  list<SimulatedPosition>  $positions  original snapshot order
     * @param  list<CopySimulationWarning>  $warnings  canonical enum order
     */
    public function __construct(
        public Money $copyAmount,
        public Money $minimumPositionAmount,
        public Money $platformMinimumCopyAmount,
        public CopyCoverageResult $coverage,
        public array $positions,
        public ?Percentage $coverageOfPositiveWeight,
        public ?Percentage $cashWeight,
        public ?Percentage $unaccountedWeight,
        public ?CoverageTargetResult $target,
        public array $warnings,
        public bool $isEstimate,
    ) {}

    public function isBelowPlatformMinimum(): bool
    {
        return $this->copyAmount->compareTo($this->platformMinimumCopyAmount) < 0;
    }
}
