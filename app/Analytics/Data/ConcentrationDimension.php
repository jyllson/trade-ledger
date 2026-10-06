<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Concentration of one grouping (PROJECT.md §13.6, D-041):
 *
 *   HHI = Σ wᵢ²   effective = 1 / HHI   largest = max wᵢ   top3 = Σ 3 largest wᵢ
 *
 * over the groups below (wᵢ on the invested-only basis). The "unknown"
 * group, when present, takes part in the metrics as ONE group — it is real
 * invested weight and must not vanish — so a Partial dimension is an
 * approximation that must be shown together with unclassifiedWeight.
 *
 * Metrics are null exactly when status is Unavailable. effectivePositions
 * is a decimal string rounded half-up to 9 places (a count, not a fraction).
 */
final readonly class ConcentrationDimension
{
    /**
     * @param  list<ConcentrationGroup>  $groups  descending by weight, ties by key (unknown last)
     * @param  numeric-string|null  $effectivePositions
     */
    public function __construct(
        public ExposureStatus $status,
        public ?ExposureUnavailableReason $unavailableReason,
        public array $groups,
        public ?Percentage $hhi,
        public ?string $effectivePositions,
        public ?ConcentrationGroup $largest,
        public ?Percentage $topThreeWeight,
        public ?Percentage $classifiedWeight,
        public ?Percentage $unclassifiedWeight,
    ) {}
}
