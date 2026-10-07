<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use DateTimeImmutable;
use OutOfBoundsException;

/**
 * Read model of the trader comparison (PROJECT.md Flow E, §14, §20 M5;
 * docs/DECISIONS.md D-047). Independent dimensions per trader, never an
 * overall score. Built from stored rows only; not persisted.
 */
final readonly class TraderComparison
{
    /**
     * @param  list<TraderComparisonEntry>  $entries  in the requested order
     */
    public function __construct(
        public string $methodologyVersion,
        public DateTimeImmutable $generatedAt,
        public int $staleAfterHours,
        public int $failedRunWindowDays,
        public array $entries,
        public ComparisonPeriods $periods,
    ) {}

    public function entry(int $traderId): TraderComparisonEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->traderId === $traderId) {
                return $entry;
            }
        }

        throw new OutOfBoundsException(sprintf('Trader %d is not part of this comparison.', $traderId));
    }
}
