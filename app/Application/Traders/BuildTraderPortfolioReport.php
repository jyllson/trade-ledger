<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;

/**
 * Builds the trader view's portfolio read model from STORED rows only —
 * never calls the eToro API, so rendering makes no HTTP request
 * (D-031/D-041/D-043).
 *
 * A private / not-found portfolio still shows the latest stored snapshot,
 * flagged as stale (TraderPortfolioReport::isStale()): the portfolio section
 * and the simulator present it as the last KNOWN portfolio, never as the
 * current one (D-045, supersedes D-043 point 1).
 *
 * Bound as a scoped singleton and memoized per trader, because the portfolio
 * widget and the simulator read the same report in one request.
 */
final class BuildTraderPortfolioReport
{
    /** @var array<int, TraderPortfolioReport> */
    private array $memo = [];

    public function __construct(
        private readonly BuildPortfolioExposureReport $exposureReport,
    ) {}

    public function handle(Trader $trader): TraderPortfolioReport
    {
        return $this->memo[$trader->id] ??= $this->build($trader);
    }

    private function build(Trader $trader): TraderPortfolioReport
    {
        $storedSnapshotCount = $trader->portfolioSnapshots()->count();

        $snapshot = $storedSnapshotCount === 0 ? null : PortfolioSnapshot::query()
            ->where('trader_id', $trader->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return new TraderPortfolioReport($trader->portfolio_visibility, $storedSnapshotCount, null, [], null);
        }

        /** @var list<PortfolioPosition> $positions */
        $positions = array_values($snapshot->positions()->with('instrument')->get()->all());

        return new TraderPortfolioReport(
            visibility: $trader->portfolio_visibility,
            storedSnapshotCount: $storedSnapshotCount,
            snapshot: $snapshot,
            positions: $positions,
            exposure: $this->exposureReport->handle($snapshot),
        );
    }
}
