<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Models\PerformanceVisibility;

/**
 * Every comparison metric of one trader, grouped by dimension
 * (PROJECT.md §14). Deliberately has no total/overall/score accessor.
 *
 * `monthlyObservation` / `dailyObservation` describe the whole stored
 * series (null when none), `snapshotObservation` the latest stored
 * snapshot (null when none) — the per-trader inputs of ComparisonPeriods.
 */
final readonly class TraderComparisonEntry
{
    /**
     * @param  array<string, ComparisonMetric>  $metrics  keyed by ComparisonMetricKey value, enum order
     */
    public function __construct(
        public int $traderId,
        public string $username,
        public ?PerformanceVisibility $performanceVisibility,
        public ?PerformanceVisibility $portfolioVisibility,
        public ?ObservationPeriod $monthlyObservation,
        public ?ObservationPeriod $dailyObservation,
        public ?ObservationPeriod $snapshotObservation,
        public ?int $portfolioSnapshotId,
        public array $metrics,
    ) {}

    public function metric(ComparisonMetricKey $key): ComparisonMetric
    {
        return $this->metrics[$key->value];
    }

    /**
     * @return list<ComparisonMetric>
     */
    public function dimension(ComparisonDimension $dimension): array
    {
        return array_values(array_filter(
            $this->metrics,
            static fn (ComparisonMetric $metric): bool => $metric->key->dimension() === $dimension,
        ));
    }
}
