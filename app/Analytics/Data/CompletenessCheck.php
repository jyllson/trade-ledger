<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * The data sources of PROJECT.md §13.8, in declaration order. Each
 * collectable check counts equally in the completeness score (D-047) — there
 * are no weights; checks the application does not collect are reported as
 * not supported and excluded from the score.
 */
enum CompletenessCheck: string
{
    case Profile = 'profile';
    case MonthlyHistory = 'monthly_history_24';
    case DailyData = 'daily_data';
    case LivePortfolio = 'live_portfolio';
    case AssetHistory = 'asset_history';
    case ExposureHistory = 'exposure_history';
    case TradeInfo = 'trade_info';
    case CopierHistory = 'copier_history';
}
