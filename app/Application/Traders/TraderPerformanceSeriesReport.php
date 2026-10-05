<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Data\ConsistencyResult;
use App\Analytics\Data\DrawdownResult;
use App\Analytics\Data\PerformanceSummary;
use App\Analytics\Data\ReturnSeries;
use DateTimeImmutable;

/**
 * Calculator results for one stored granularity of one trader. `asOf` is
 * the capture time used to decide the in-progress period (the latest
 * `synced_at` of that series).
 */
final readonly class TraderPerformanceSeriesReport
{
    public function __construct(
        public ReturnSeries $series,
        public DateTimeImmutable $asOf,
        public PerformanceSummary $summary,
        public DrawdownResult $drawdown,
        public ConsistencyResult $consistency,
    ) {}
}
