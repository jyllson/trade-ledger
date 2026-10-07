<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnDistributionResult;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\ReturnMath;
use App\Analytics\ValueObjects\Percentage;

/**
 * Dispersion of per-period returns and dependency on the best period
 * (PROJECT.md §14 Consistency; docs/DECISIONS.md D-047). Uses COMPLETE
 * periods only (D-032/D-033). Pure — no Laravel, no I/O.
 *
 * - Quartiles use linear interpolation between closest ranks (Hyndman &
 *   Fan type 7, the spreadsheet/NumPy default): with the n complete returns
 *   sorted ascending x₀ … xₙ₋₁, h = (n − 1) × p and
 *   Q(p) = x⌊h⌋ + (h − ⌊h⌋) × (x⌊h⌋₊₁ − x⌊h⌋). Dispersion = IQR = Q(0.75) − Q(0.25);
 *   needs at least MINIMUM_DISPERSION_PERIODS returns.
 * - Best-period contribution = R − R₋best, where R = Π(1 + rₜ) − 1 over all
 *   complete periods and R₋best the same without the single best period
 *   (needs at least two periods). Share of return = contribution / R, only
 *   when R > 0 (a share of a non-positive return has no meaning).
 *
 * Exact BCMath (ReturnMath::SCALE); rounded half-up to ppb only at the end.
 */
final class ReturnDistributionCalculator
{
    public const METHODOLOGY_VERSION = 'return-distribution-v1';

    public const MINIMUM_DISPERSION_PERIODS = 4;

    public function calculate(ReturnSeries $series): ReturnDistributionResult
    {
        $returns = array_map(static fn (PeriodReturn $period): Percentage => $period->return, $series->completePeriods());
        $count = count($returns);

        $ascending = $returns;
        usort($ascending, static fn (Percentage $a, Percentage $b): int => $a->compareTo($b));

        $firstQuartile = null;
        $thirdQuartile = null;
        $interquartileRange = null;

        if ($ascending !== [] && $count >= self::MINIMUM_DISPERSION_PERIODS) {
            $firstQuartile = $this->quantile($ascending, '0.25');
            $thirdQuartile = $this->quantile($ascending, '0.75');
            $interquartileRange = bcsub($thirdQuartile, $firstQuartile, ReturnMath::SCALE);
        }

        $contribution = null;
        $share = null;

        if ($count >= 2) {
            $all = bcsub(ReturnMath::growthFactor($returns), '1', ReturnMath::SCALE);
            $withoutBest = bcsub(ReturnMath::growthFactor(array_slice($ascending, 0, -1)), '1', ReturnMath::SCALE);
            $contribution = bcsub($all, $withoutBest, ReturnMath::SCALE);

            if (bccomp($all, '0', ReturnMath::SCALE) > 0) {
                $share = bcdiv($contribution, $all, ReturnMath::SCALE);
            }
        }

        return new ReturnDistributionResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            granularity: $series->granularity,
            observedCount: $count,
            firstQuartile: $firstQuartile === null ? null : ReturnMath::toPercentage($firstQuartile),
            thirdQuartile: $thirdQuartile === null ? null : ReturnMath::toPercentage($thirdQuartile),
            interquartileRange: $interquartileRange === null ? null : ReturnMath::toPercentage($interquartileRange),
            bestPeriodContribution: $contribution === null ? null : ReturnMath::toPercentage($contribution),
            bestPeriodShareOfReturn: $share === null ? null : ReturnMath::toPercentage($share),
        );
    }

    /**
     * Type 7 quantile of an ascending, non-empty list (exact).
     *
     * @param  non-empty-list<Percentage>  $ascending
     * @param  numeric-string  $probability
     * @return numeric-string
     */
    private function quantile(array $ascending, string $probability): string
    {
        $position = bcmul((string) (count($ascending) - 1), $probability, ReturnMath::SCALE);
        $lowerIndex = (int) bcadd($position, '0', 0);
        $fraction = bcsub($position, (string) $lowerIndex, ReturnMath::SCALE);
        $lower = ReturnMath::toFraction($ascending[$lowerIndex]);

        if (bccomp($fraction, '0', ReturnMath::SCALE) === 0) {
            return $lower;
        }

        $upper = ReturnMath::toFraction($ascending[$lowerIndex + 1]);

        return bcadd($lower, bcmul($fraction, bcsub($upper, $lower, ReturnMath::SCALE), ReturnMath::SCALE), ReturnMath::SCALE);
    }
}
