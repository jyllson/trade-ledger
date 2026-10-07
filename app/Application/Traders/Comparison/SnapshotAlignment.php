<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use DateTimeImmutable;

/**
 * When the compared traders' latest snapshots were captured — copyability,
 * concentration, and leverage are point-in-time values, so the spread of
 * capture times is shown instead of a common period.
 */
final readonly class SnapshotAlignment
{
    /**
     * @param  array<int, ObservationPeriod|null>  $observations  keyed by trader id, null = no snapshot
     * @param  list<int>  $tradersWithoutSnapshot
     */
    public function __construct(
        public array $observations,
        public ?DateTimeImmutable $oldestCapturedAt,
        public ?DateTimeImmutable $newestCapturedAt,
        public array $tradersWithoutSnapshot,
    ) {}

    /**
     * @param  array<int, ObservationPeriod|null>  $observations
     */
    public static function of(array $observations): self
    {
        $captured = array_values(array_filter(array_map(
            static fn (?ObservationPeriod $observation): ?DateTimeImmutable => $observation?->from,
            $observations,
        )));

        return new self(
            observations: $observations,
            oldestCapturedAt: $captured === [] ? null : min($captured),
            newestCapturedAt: $captured === [] ? null : max($captured),
            tradersWithoutSnapshot: array_keys(array_filter($observations, static fn (?ObservationPeriod $observation): bool => $observation === null)),
        );
    }

    /**
     * Not every trader has a snapshot, or the snapshots were captured at
     * different instants.
     */
    public function differs(): bool
    {
        return ($this->tradersWithoutSnapshot !== [] && count($this->tradersWithoutSnapshot) !== count($this->observations))
            || $this->oldestCapturedAt != $this->newestCapturedAt;
    }
}
