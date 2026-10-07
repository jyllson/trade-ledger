<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Comparison-level view of the observation periods (docs/DECISIONS.md
 * D-047): monthly and daily series alignment and snapshot capture spread.
 */
final readonly class ComparisonPeriods
{
    public function __construct(
        public SeriesAlignment $monthly,
        public SeriesAlignment $daily,
        public SnapshotAlignment $snapshots,
    ) {}

    /**
     * Whether the UI must warn that the traders are not observed over the
     * same periods (PROJECT.md §15, §20 M5 acceptance).
     */
    public function observationPeriodsDiffer(): bool
    {
        return $this->monthly->differs || $this->daily->differs || $this->snapshots->differs();
    }
}
