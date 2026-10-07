<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * What an ObservationPeriod describes.
 *
 * - ReturnSeries: stored performance periods (from/to = first/last period
 *   start, inclusive; pointCount = periods used).
 * - PortfolioSnapshot: one stored snapshot (from = captured_at, to =
 *   last_confirmed_at; pointCount = 1).
 * - ImportRunWindow: import runs started within [from, to] (pointCount =
 *   runs counted).
 * - SyncRecord: a single timestamp kept on the trader row (to = that
 *   timestamp; pointCount = 1), e.g. the last successful sync and the
 *   visibility that sync observed.
 * - EvaluatedAt: a state evaluated at the comparison instant (to = that
 *   instant; pointCount = 1), e.g. freshness, completeness, and the risk
 *   score the application does not collect.
 * - NoData: nothing stored to observe (no series, no snapshot, never
 *   synced, no complete period) — an explicit empty period: from = null,
 *   to = the comparison instant it was evaluated at, pointCount = 0.
 *
 * Every comparison metric carries a period with one of these bases — never
 * null — so the UI shows the period of every value the same way.
 */
enum ObservationBasis: string
{
    case ReturnSeries = 'return_series';
    case PortfolioSnapshot = 'portfolio_snapshot';
    case ImportRunWindow = 'import_run_window';
    case SyncRecord = 'sync_record';
    case EvaluatedAt = 'evaluated_at';
    case NoData = 'no_data';
}
