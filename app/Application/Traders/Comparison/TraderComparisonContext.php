<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Application\Traders\CopySimulationMatrix;
use App\Application\Traders\TraderPerformanceSeriesReport;
use App\Application\Traders\TraderPortfolioReport;
use App\Models\ImportRun;
use App\Models\Trader;
use Carbon\CarbonImmutable;

/**
 * Inputs of one trader's comparison entry, gathered once by
 * BuildTraderComparison (internal).
 *
 * @internal
 */
final readonly class TraderComparisonContext
{
    /**
     * @param  list<ImportRun>  $importRuns  performance/portfolio runs in the failed-run window
     * @param  ObservationPeriod  $noData  the explicit empty period at the comparison instant
     */
    public function __construct(
        public Trader $trader,
        public CarbonImmutable $now,
        public ?TraderPerformanceSeriesReport $monthly,
        public ?TraderPerformanceSeriesReport $daily,
        public TraderPortfolioReport $portfolio,
        public ?CopySimulationMatrix $matrix,
        public DataFreshness $performanceFreshness,
        public DataFreshness $portfolioFreshness,
        public array $importRuns,
        public ObservationPeriod $noData,
        public ?ObservationPeriod $snapshotObservation,
    ) {}
}
