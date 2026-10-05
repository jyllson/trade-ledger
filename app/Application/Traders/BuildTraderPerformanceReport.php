<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Calculators\ConsistencyCalculator;
use App\Analytics\Calculators\DrawdownCalculator;
use App\Analytics\Calculators\PerformanceCalculator;
use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use App\Analytics\Data\ReturnSeries;
use App\Analytics\Support\PeriodClassifier;
use App\Analytics\ValueObjects\Percentage;
use App\Models\PerformancePoint;
use App\Models\Trader;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the performance read model from STORED points only — never calls
 * the eToro API, so rendering the UI makes no HTTP request (D-031/D-036).
 *
 * Partial-start / in-progress flags are derived here (PeriodClassifier)
 * from the stored dates and the series' latest `synced_at`, never stored.
 *
 * Bound as a scoped singleton: results are memoized per trader and per
 * sync timestamp for the current request, because the infolist and several
 * widgets read the same report.
 */
final class BuildTraderPerformanceReport
{
    /** @var array<string, TraderPerformanceReport> */
    private array $memo = [];

    public function __construct(
        private readonly PerformanceCalculator $performanceCalculator,
        private readonly DrawdownCalculator $drawdownCalculator,
        private readonly ConsistencyCalculator $consistencyCalculator,
    ) {}

    public function handle(Trader $trader): TraderPerformanceReport
    {
        $key = $trader->id.'|'.($trader->performance_synced_at?->getTimestamp() ?? '-').'|'.($trader->performance_visibility->value ?? '-');

        return $this->memo[$key] ??= new TraderPerformanceReport(
            monthly: $this->seriesReport($trader, ReturnPeriodGranularity::Monthly),
            daily: $this->seriesReport($trader, ReturnPeriodGranularity::Daily),
            visibility: $trader->performance_visibility,
            lastSyncedAt: $trader->performance_synced_at?->toDateTimeImmutable(),
        );
    }

    private function seriesReport(Trader $trader, ReturnPeriodGranularity $granularity): ?TraderPerformanceSeriesReport
    {
        $points = PerformancePoint::query()
            ->where('trader_id', $trader->id)
            ->where('granularity', $granularity->value)
            ->where('source', PerformancePoint::SOURCE_ETORO_V2_GAIN)
            ->orderBy('period_start')
            ->get(['period_start', 'gain_ppb', 'synced_at']);

        if ($points->isEmpty()) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $asOf = $points->max('synced_at')->toDateTimeImmutable();
        $lastIndex = $points->count() - 1;
        $periods = [];

        foreach ($points->values() as $index => $point) {
            $date = new DateTimeImmutable($point->period_start, $utc);

            $periods[] = new PeriodReturn(
                periodStart: $date,
                return: Percentage::fromPartsPerBillion($point->gain_ppb),
                isPartialStart: $index === 0 && ! PeriodClassifier::isPeriodStart($granularity, $date),
                isInProgress: $index === $lastIndex && PeriodClassifier::containsInstant($granularity, $date, $asOf),
            );
        }

        $series = new ReturnSeries($granularity, $periods);

        return new TraderPerformanceSeriesReport(
            series: $series,
            asOf: $asOf,
            summary: $this->performanceCalculator->calculate($series),
            drawdown: $this->drawdownCalculator->calculate($series),
            consistency: $this->consistencyCalculator->calculate($series),
        );
    }
}
