<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Derived, unweighted summary of a trader's criterion outcomes (D-048) —
 * not a score: it only says which outcomes occur, by fixed precedence
 * fail > unknown > pass.
 */
enum ProfileFilterVerdict: string
{
    /** At least one applied criterion failed (other criteria may be unknown). */
    case AtLeastOneFailed = 'at_least_one_failed';

    /** None failed, but at least one applied criterion is unknown. */
    case AtLeastOneUnknown = 'at_least_one_unknown';

    /** Every applied criterion passed. */
    case AllAppliedPassed = 'all_applied_passed';

    /** No criterion is applied (only possible without a target, which the profile always has). */
    case NoneApplied = 'none_applied';
}
