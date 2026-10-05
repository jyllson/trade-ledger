<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;

/**
 * Result of DrawdownCalculator over every period of a series.
 *
 * maxDrawdown is a non-negative magnitude (0.25 = a 25% drop). It is
 * measured at the series granularity only — a monthly max drawdown is NOT
 * an intraday or daily drawdown (PROJECT.md §13.3).
 *
 * peakPeriodStart is null when the peak is the starting equity (before the
 * first period). recoveryPeriodStart is the first period after the trough
 * whose equity regains the peak, or null if not yet recovered.
 */
final readonly class DrawdownResult
{
    /**
     * @param  list<DrawdownPoint>  $drawdownSeries
     */
    public function __construct(
        public string $methodologyVersion,
        public ReturnPeriodGranularity $granularity,
        public Percentage $maxDrawdown,
        public ?DateTimeImmutable $peakPeriodStart,
        public ?DateTimeImmutable $troughPeriodStart,
        public ?DateTimeImmutable $recoveryPeriodStart,
        public array $drawdownSeries,
    ) {}
}
