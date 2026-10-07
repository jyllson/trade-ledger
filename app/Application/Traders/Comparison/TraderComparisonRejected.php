<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use InvalidArgumentException;

/**
 * The requested trader selection cannot be compared (2–10 distinct,
 * existing traders — PROJECT.md Flow E, §15). Nothing was computed.
 */
final class TraderComparisonRejected extends InvalidArgumentException
{
    /**
     * @param  list<int>  $traderIds  the offending ids (duplicates / unknown), empty for count errors
     */
    public function __construct(
        public readonly TraderComparisonRejectionReason $reason,
        public readonly array $traderIds = [],
    ) {
        parent::__construct(match ($reason) {
            TraderComparisonRejectionReason::TooFewTraders => sprintf('Select at least %d traders to compare.', BuildTraderComparison::MINIMUM_TRADERS),
            TraderComparisonRejectionReason::TooManyTraders => sprintf('Select at most %d traders to compare.', BuildTraderComparison::MAXIMUM_TRADERS),
            TraderComparisonRejectionReason::DuplicateTrader => 'Each trader can be selected only once.',
            TraderComparisonRejectionReason::UnknownTrader => 'Some selected traders do not exist.',
        });
    }
}
