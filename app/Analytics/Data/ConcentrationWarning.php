<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Declaration order is the canonical order of ConcentrationResult::$warnings;
 * each warning appears at most once.
 */
enum ConcentrationWarning: string
{
    case MissingPositionWeight = 'missing_position_weight';
    case NegativeWeightIgnored = 'negative_weight_ignored';
    case AssetClassUnknown = 'asset_class_unknown';
    case SectorUnknown = 'sector_unknown';
    case CashWeightUnknown = 'cash_weight_unknown';
    case NoInvestedWeight = 'no_invested_weight';
}
