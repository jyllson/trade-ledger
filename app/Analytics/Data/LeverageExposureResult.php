<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Result of LeverageExposureCalculator (PROJECT.md §13.7, D-041).
 *
 * Weighted metrics use positions with a usable weight, invested-only basis:
 * - leveragedWeight + unleveragedWeight + unknownLeverageWeight = 1 (up to
 *   ppb rounding); unknown covers missing AND invalid (< 1) leverage — it
 *   is never assumed to be 1x;
 * - knownLeverageContribution = Σ(wᵢ × leverageᵢ) over positions with a
 *   known leverage, wᵢ normalized to the WHOLE invested weight (not
 *   renormalized to the known part); knownLeverageWeight = Σ wᵢ of those
 *   positions (= leveragedWeight + unleveragedWeight);
 * - weightedLeverage = §13.7's Σ(wᵢ × leverageᵢ) exactly; it is only
 *   determinable when unknownLeverageWeight = 0, and then equals
 *   knownLeverageContribution. With any unknown leverage weight it is null.
 *   Leverage values are decimal strings rounded half-up to 9 places.
 *
 * weightedLeverage is null whenever status is Unavailable; the weight
 * split and knownLeverageContribution are null only for NoInvestedWeight
 * (with NoData the split is 100% unknown and the contribution 0).
 *
 * Counts and maxLeverage use every holding with a valid leverage, weight
 * known or not (a count needs no weight).
 *
 * missingWeightCount / negativeWeightCount (same meaning as in
 * ConcentrationResult) = holdings excluded from every weighted metric
 * because their weight is unknown or negative. Any such holding makes the
 * result Partial; weightedLeverage is still reported over the invested
 * basis of the usable weights (it never covers the excluded positions).
 */
final readonly class LeverageExposureResult
{
    /**
     * @param  numeric-string|null  $weightedLeverage
     * @param  numeric-string|null  $knownLeverageContribution
     */
    public function __construct(
        public string $methodologyVersion,
        public ExposureWeightBasis $weightBasis,
        public ExposureStatus $status,
        public ?ExposureUnavailableReason $unavailableReason,
        public ?string $weightedLeverage,
        public ?string $knownLeverageContribution,
        public ?Percentage $knownLeverageWeight,
        public ?Percentage $leveragedWeight,
        public ?Percentage $unleveragedWeight,
        public ?Percentage $unknownLeverageWeight,
        public ?int $maxLeverage,
        public int $leveragedPositionCount,
        public int $knownLeverageCount,
        public int $missingLeverageCount,
        public int $invalidLeverageCount,
        public int $missingWeightCount,
        public int $negativeWeightCount,
    ) {}
}
