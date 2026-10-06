<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Denominator of every weight in ConcentrationResult and
 * LeverageExposureResult (D-041).
 *
 * InvestedOnly: wᵢ = weightᵢ / Σ usable position weights — the
 * "invested-only" view allowed by PROJECT.md §12.5. Cash is reported next
 * to it, never as a position.
 */
enum ExposureWeightBasis: string
{
    case InvestedOnly = 'invested_only';
}
