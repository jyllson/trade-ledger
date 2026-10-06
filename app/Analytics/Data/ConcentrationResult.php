<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Result of ConcentrationCalculator (D-041).
 *
 * Completeness metadata, on the WHOLE-portfolio basis (as stored):
 * - investedWeight: Σ usable position weights (the invested-only denominator);
 * - cashWeight: as received, null ⇒ unknown;
 * - unaccountedWeight: 1 − investedWeight − cashWeight (§12.5
 *   `unknown_weight`); null when cash is unknown, may be negative.
 *
 * Counts: positionCount = all holdings; weightedPositionCount = holdings
 * with a usable weight; missingWeightCount / negativeWeightCount = holdings
 * excluded from every weight-based metric.
 */
final readonly class ConcentrationResult
{
    /**
     * @param  list<ConcentrationWarning>  $warnings
     */
    public function __construct(
        public string $methodologyVersion,
        public ExposureWeightBasis $weightBasis,
        public ConcentrationDimension $byInstrument,
        public ConcentrationDimension $byAssetClass,
        public ConcentrationDimension $bySector,
        public int $positionCount,
        public int $weightedPositionCount,
        public int $missingWeightCount,
        public int $negativeWeightCount,
        public Percentage $investedWeight,
        public ?Percentage $cashWeight,
        public ?Percentage $unaccountedWeight,
        public array $warnings,
    ) {}
}
