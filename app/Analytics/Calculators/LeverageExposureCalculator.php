<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\ExposureWeightBasis;
use App\Analytics\Data\LeverageExposureResult;
use App\Analytics\Data\PortfolioHoldings;
use App\Analytics\Support\WeightMath;

/**
 * Leverage exposure (PROJECT.md §13.7, D-041). Pure — no Laravel, no I/O.
 *
 * A missing or invalid (< 1) leverage is never assumed to be 1x: its
 * weight is reported as unknownLeverageWeight and the result is Partial.
 * While any invested weight has an unknown leverage, weightedLeverage is
 * not determinable (null); only knownLeverageContribution on the same
 * invested-only basis is reported — never a renormalized average.
 *
 * A position without a usable weight (missing or negative) is outside the
 * invested basis by definition (as in ConcentrationCalculator): it is
 * counted (missingWeightCount / negativeWeightCount), the result is
 * Partial, and weightedLeverage stays a value over the known invested
 * basis — it is not made null, because the excluded position changes the
 * basis W, not the determinability of Σ(wᵢ × Lᵢ) over W.
 */
final class LeverageExposureCalculator
{
    public const METHODOLOGY_VERSION = 'leverage-v1';

    public function calculate(PortfolioHoldings $portfolio): LeverageExposureResult
    {
        $investedPpb = '0';
        $knownLeveragePpb = '0';
        $leverageProductPpb = '0';
        $leveragedPpb = '0';
        $unleveragedPpb = '0';
        $unknownLeveragePpb = '0';

        $knownCount = 0;
        $missingCount = 0;
        $invalidCount = 0;
        $missingWeightCount = 0;
        $negativeWeightCount = 0;
        $leveragedCount = 0;
        $maxLeverage = null;

        foreach ($portfolio->holdings as $holding) {
            $leverage = $holding->validLeverage();

            if ($holding->leverage === null) {
                $missingCount++;
            } elseif ($leverage === null) {
                $invalidCount++;
            } else {
                $knownCount++;
                $leveragedCount += $leverage > 1 ? 1 : 0;
                $maxLeverage = max($maxLeverage ?? $leverage, $leverage);
            }

            $weightPpb = $holding->usableWeightPpb();

            if ($weightPpb === null) {
                $holding->weight === null ? $missingWeightCount++ : $negativeWeightCount++;

                continue;
            }

            $weight = (string) $weightPpb;
            $investedPpb = bcadd($investedPpb, $weight, 0);

            if ($leverage === null) {
                $unknownLeveragePpb = bcadd($unknownLeveragePpb, $weight, 0);

                continue;
            }

            $knownLeveragePpb = bcadd($knownLeveragePpb, $weight, 0);
            $leverageProductPpb = bcadd($leverageProductPpb, bcmul($weight, (string) $leverage, 0), 0);

            if ($leverage > 1) {
                $leveragedPpb = bcadd($leveragedPpb, $weight, 0);
            } else {
                $unleveragedPpb = bcadd($unleveragedPpb, $weight, 0);
            }
        }

        $hasInvestedWeight = bccomp($investedPpb, '0', 0) > 0;
        $hasKnownLeverageWeight = bccomp($knownLeveragePpb, '0', 0) > 0;
        $hasUnknownLeverageWeight = bccomp($unknownLeveragePpb, '0', 0) > 0;

        $status = match (true) {
            ! $hasInvestedWeight, ! $hasKnownLeverageWeight => ExposureStatus::Unavailable,
            $missingCount + $invalidCount + $missingWeightCount + $negativeWeightCount > 0 => ExposureStatus::Partial,
            default => ExposureStatus::Complete,
        };

        return new LeverageExposureResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            weightBasis: ExposureWeightBasis::InvestedOnly,
            status: $status,
            unavailableReason: match (true) {
                ! $hasInvestedWeight => ExposureUnavailableReason::NoInvestedWeight,
                ! $hasKnownLeverageWeight => ExposureUnavailableReason::NoData,
                default => null,
            },
            weightedLeverage: $hasKnownLeverageWeight && ! $hasUnknownLeverageWeight
                ? WeightMath::decimal($leverageProductPpb, $investedPpb)
                : null,
            knownLeverageContribution: $hasInvestedWeight ? WeightMath::decimal($leverageProductPpb, $investedPpb) : null,
            knownLeverageWeight: $hasInvestedWeight ? WeightMath::ratio($knownLeveragePpb, $investedPpb) : null,
            leveragedWeight: $hasInvestedWeight ? WeightMath::ratio($leveragedPpb, $investedPpb) : null,
            unleveragedWeight: $hasInvestedWeight ? WeightMath::ratio($unleveragedPpb, $investedPpb) : null,
            unknownLeverageWeight: $hasInvestedWeight ? WeightMath::ratio($unknownLeveragePpb, $investedPpb) : null,
            maxLeverage: $maxLeverage,
            leveragedPositionCount: $leveragedCount,
            knownLeverageCount: $knownCount,
            missingLeverageCount: $missingCount,
            invalidLeverageCount: $invalidCount,
            missingWeightCount: $missingWeightCount,
            negativeWeightCount: $negativeWeightCount,
        );
    }
}
