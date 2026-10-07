<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Application\Traders\CopySimulationInput;
use App\Application\Traders\CopySimulationSettings;

/**
 * Built-in default and bounds of an analysis profile (PROJECT.md §11
 * `analysis_profiles`, docs/DECISIONS.md D-048). Money in USD cents,
 * fractions in ppb (1.0 = 10⁹).
 */
final class AnalysisProfileSettings
{
    public const string DEFAULT_NAME = 'Default';

    /** $500 — the middle simulator preset (D-048). */
    public const int DEFAULT_BUDGET_CENTS = 50_000;

    /** 95% of the visible positive position weight (§11 default 0.95). */
    public const int DEFAULT_TARGET_COVERAGE_PPB = 950_000_000;

    /** A budget below the eToro minimum copy amount ($200) cannot copy anyone. */
    public const int MINIMUM_BUDGET_CENTS = CopySimulationSettings::PLATFORM_MINIMUM_COPY_CENTS;

    /** The simulator's representable upper bound, $10,000,000 (D-043). */
    public const int MAXIMUM_BUDGET_CENTS = CopySimulationInput::MAXIMUM_AMOUNT_CENTS;

    /** eToro's risk score scale is 1–10. */
    public const int MINIMUM_RISK_SCORE = 1;

    public const int MAXIMUM_RISK_SCORE = 10;

    public const int MINIMUM_HISTORY_MONTHS = 1;

    /** 50 years — a sanity bound, far above any eToro history. */
    public const int MAXIMUM_HISTORY_MONTHS = 600;

    public const int WHOLE_PPB = 1_000_000_000;
}
