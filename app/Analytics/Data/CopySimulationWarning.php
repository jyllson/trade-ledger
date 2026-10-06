<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Simulation-level data-quality signals (docs/DECISIONS.md D-042).
 * Declaration order is the canonical order of CopySimulationResult::$warnings.
 *
 * CopyCoverageCalculator's `observed_weight_not_whole` is deliberately not
 * mirrored: positions never add up to 100% when the portfolio holds cash
 * (PROJECT.md §12.5), so the simulator replaces it with the cash-aware
 * CashWeightUnknown / UnaccountedWeight pair.
 */
enum CopySimulationWarning: string
{
    case EmptySnapshot = 'empty_snapshot';
    case NoPositiveWeight = 'no_positive_weight';
    case DuplicatePositionId = 'duplicate_position_id';
    case NegativeWeightIgnored = 'negative_weight_ignored';
    case UnmodeledEntriesPresent = 'unmodeled_portfolio_entries_present';
    case CashWeightUnknown = 'cash_weight_unknown';
    case UnaccountedWeight = 'unaccounted_weight';
    case CopyAmountBelowPlatformMinimum = 'copy_amount_below_platform_minimum';

    /**
     * Whether the warning makes the simulated numbers an estimate
     * (PROJECT.md §12.2 step 6): part of the portfolio is missing, invalid,
     * or not modelled. An empty or zero-weight snapshot is complete but
     * uncoverable (D-022), and an amount below the platform minimum is an
     * input problem, not a data problem.
     */
    public function makesEstimate(): bool
    {
        return match ($this) {
            self::DuplicatePositionId,
            self::NegativeWeightIgnored,
            self::UnmodeledEntriesPresent,
            self::CashWeightUnknown,
            self::UnaccountedWeight => true,
            self::EmptySnapshot,
            self::NoPositiveWeight,
            self::CopyAmountBelowPlatformMinimum => false,
        };
    }
}
