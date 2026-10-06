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
 * `snapshot` is the latest stored snapshot, or null when none is stored.
 * When the last sync found the portfolio private / not found, that snapshot
 * is the last KNOWN portfolio and may be outdated — isStale() (D-045).
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

    public function isNoLongerVisible(): bool
    {
        return $this->visibility === PerformanceVisibility::Private
            || $this->visibility === PerformanceVisibility::NotFound;
    }

    /**
     * A stored snapshot exists, but the portfolio can no longer be observed:
     * it is shown as the last known snapshot, never as the current one.
     */
    public function isStale(): bool
    {
        return $this->snapshot !== null && $this->isNoLongerVisible();
    }
}
