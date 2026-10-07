<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Outcome of one analysis profile criterion for one trader (D-048).
 * Unknown is never folded into pass or fail.
 */
enum CriterionOutcome: string
{
    /** The trader's value satisfies the threshold (equality passes). */
    case Pass = 'pass';

    /** The trader's value violates the threshold. */
    case Fail = 'fail';

    /** The value is unavailable or only partially determined — neither pass nor fail. */
    case Unknown = 'unknown';

    /** The profile leaves this criterion empty (null). */
    case NotApplied = 'not_applied';

    /** Shown with the profile but not a property of the trader, so never evaluated. */
    case Informational = 'informational';

    public function isApplied(): bool
    {
        return $this === self::Pass || $this === self::Fail || $this === self::Unknown;
    }
}
