<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\DrawdownPoint;
use App\Analytics\Data\DrawdownResult;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\ReturnMath;

/**
 * Maximum drawdown from the equity index (PROJECT.md §13.3):
 *
 *   peakₜ = max(equity₀ … equityₜ), equity₀ = 1
 *   drawdownₜ = equityₜ / peakₜ − 1
 *   max_drawdown = |min(drawdownₜ)|
 *
 * Uses every period (a partial first or in-progress last period is real
 * equity movement). The result is only as fine as the series granularity.
 */
final class DrawdownCalculator
{
    public const METHODOLOGY_VERSION = 'drawdown-v1';

    public function calculate(ReturnSeries $series): DrawdownResult
    {
        $equity = '1';
        $peak = '1';
        $peakPpb = 1_000_000_000;
        $peakStart = null;

        $worst = '0';
        $worstPeakStart = null;
        $troughStart = null;
        $recoveryStart = null;
        $points = [];

        foreach ($series->periods as $period) {
            $equity = bcmul($equity, bcadd('1', ReturnMath::toFraction($period->return), ReturnMath::SCALE), ReturnMath::SCALE);

            // Peak/recovery decisions use equity rounded to the result
            // precision (ppb): 18-digit truncation can leave an exact
            // return to the peak 1e-18 below it, which must still count as
            // recovered and report a 0 drawdown.
            $equityPpb = ReturnMath::toPercentage($equity)->partsPerBillion();

            if ($equityPpb >= $peakPpb) {
                if ($troughStart !== null && $recoveryStart === null && $peakStart === $worstPeakStart) {
                    $recoveryStart = $period->periodStart;
                }

                $peak = $equity;
                $peakPpb = $equityPpb;
                $peakStart = $period->periodStart;
            }

            $drawdown = bcsub(bcdiv($equity, $peak, ReturnMath::SCALE), '1', ReturnMath::SCALE);
            $points[] = new DrawdownPoint($period->periodStart, ReturnMath::toPercentage($drawdown));

            if (bccomp($drawdown, $worst, ReturnMath::SCALE) < 0) {
                $worst = $drawdown;
                $worstPeakStart = $peakStart;
                $troughStart = $period->periodStart;
                $recoveryStart = null;
            }
        }

        return new DrawdownResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            granularity: $series->granularity,
            maxDrawdown: ReturnMath::toPercentage(bcmul($worst, '-1', ReturnMath::SCALE)),
            peakPeriodStart: $troughStart === null ? null : $worstPeakStart,
            troughPeriodStart: $troughStart,
            recoveryPeriodStart: $recoveryStart,
            drawdownSeries: $points,
        );
    }
}
