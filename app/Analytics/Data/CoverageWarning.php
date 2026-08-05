<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Declaration order is the canonical warning order for CopyCoverageResult
 * and CoverageTargetResult: any warning list produced by
 * App\Analytics\Calculators\CopyCoverageCalculator must list warnings in
 * exactly this order, and each warning at most once.
 */
enum CoverageWarning: string
{
    case DuplicatePositionId = 'duplicate_position_id';
    case NegativeWeightIgnored = 'negative_weight_ignored';
    case ObservedWeightNotWhole = 'observed_weight_not_whole';
    case UnmodeledPortfolioEntriesPresent = 'unmodeled_portfolio_entries_present';
    case NoPositiveWeightObserved = 'no_positive_weight_observed';
}
