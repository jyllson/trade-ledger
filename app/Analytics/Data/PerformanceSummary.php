<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;

/**
 * Result of PerformanceCalculator. All returns are decimal fractions.
 *
 * - cumulativeReturn and equityCurve use EVERY period, including a partial
 *   first period and an in-progress last period.
 * - trailing12/24Return use the last 12/24 COMPLETE periods and exist only
 *   for monthly series with enough complete periods.
 * - average/median use complete periods only (arithmetic, per period —
 *   never annualized).
 */
final readonly class PerformanceSummary
{
    /**
     * @param  list<EquityPoint>  $equityCurve
     */
    public function __construct(
        public string $methodologyVersion,
        public ReturnPeriodGranularity $granularity,
        public int $periodCount,
        public int $completePeriodCount,
        public ?DateTimeImmutable $firstPeriodStart,
        public ?DateTimeImmutable $lastPeriodStart,
        public bool $hasPartialStart,
        public bool $hasInProgressPeriod,
        public ?Percentage $cumulativeReturn,
        public ?Percentage $trailing12Return,
        public ?Percentage $trailing24Return,
        public ?Percentage $averageReturn,
        public ?Percentage $medianReturn,
        public array $equityCurve,
    ) {}
}
