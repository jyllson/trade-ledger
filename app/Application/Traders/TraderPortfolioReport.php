<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\PerformanceVisibility;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;

/**
 * Read model of the trader view's portfolio section and copy simulator
 * (docs/DECISIONS.md D-043). Built from stored rows only.
 *
 * `snapshot` is the latest stored snapshot, or null when none is stored OR
 * when the last sync found the portfolio private / not found — older
 * snapshots are then no longer current and are not shown (they stay
 * stored, `storedSnapshotCount`).
 */
final readonly class TraderPortfolioReport
{
    /**
     * @param  list<PortfolioPosition>  $positions  snapshot order, instrument loaded
     */
    public function __construct(
        public ?PerformanceVisibility $visibility,
        public int $storedSnapshotCount,
        public ?PortfolioSnapshot $snapshot,
        public array $positions,
        public ?PortfolioExposureReport $exposure,
    ) {}

    public function isAvailable(): bool
    {
        return $this->snapshot !== null;
    }

    public function isHiddenByVisibility(): bool
    {
        return $this->visibility === PerformanceVisibility::Private
            || $this->visibility === PerformanceVisibility::NotFound;
    }
}
