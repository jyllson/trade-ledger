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
        return $this->memo[$this->memoKey($trader)] ??= $this->report(
            $trader,
            $this->points([$trader->id])[$trader->id] ?? [],
        );
    }

    /**
     * The same report as handle() for several traders, with the stored
     * points of all of them read in ONE query (trader comparison, D-047).
     *
     * @param  list<Trader>  $traders
     * @return array<int, TraderPerformanceReport> keyed by trader id
     */
    public function handleMany(array $traders): array
    {
        $missing = array_values(array_filter($traders, fn (Trader $trader): bool => ! isset($this->memo[$this->memoKey($trader)])));
        $points = $missing === [] ? [] : $this->points(array_map(static fn (Trader $trader): int => $trader->id, $missing));

        $reports = [];

        foreach ($traders as $trader) {
            $reports[$trader->id] = $this->memo[$this->memoKey($trader)] ??= $this->report($trader, $points[$trader->id] ?? []);
        }

        return $reports;
    }

    private function memoKey(Trader $trader): string
    {
        return $trader->id.'|'.($trader->performance_synced_at?->getTimestamp() ?? '-').'|'.($trader->performance_visibility->value ?? '-');
    }

    /**
     * @param  list<PerformancePoint>  $points  this trader's points, both granularities
     */
    private function report(Trader $trader, array $points): TraderPerformanceReport
    {
        return new TraderPerformanceReport(
            monthly: $this->seriesReport(ReturnPeriodGranularity::Monthly, $points),
            daily: $this->seriesReport(ReturnPeriodGranularity::Daily, $points),
            visibility: $trader->performance_visibility,
            lastSyncedAt: $trader->performance_synced_at?->toDateTimeImmutable(),
        );
    }

    /**
     * Stored eToro gain points of the given traders, grouped by trader id,
     * ordered by period start.
     *
     * @param  list<int>  $traderIds
     * @return array<int, list<PerformancePoint>>
     */
    private function points(array $traderIds): array
    {
        $points = PerformancePoint::query()
            ->whereIn('trader_id', $traderIds)
            ->where('source', PerformancePoint::SOURCE_ETORO_V2_GAIN)
            ->orderBy('period_start')
            ->get(['trader_id', 'granularity', 'period_start', 'gain_ppb', 'synced_at']);

        $byTrader = [];

        foreach ($points as $point) {
            $byTrader[$point->trader_id][] = $point;
        }

        return $byTrader;
    }

    /**
     * @param  list<PerformancePoint>  $traderPoints  ordered by period start
     */
    private function seriesReport(ReturnPeriodGranularity $granularity, array $traderPoints): ?TraderPerformanceSeriesReport
    {
        $points = collect($traderPoints)->filter(static fn (PerformancePoint $point): bool => $point->granularity === $granularity)->values();

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
