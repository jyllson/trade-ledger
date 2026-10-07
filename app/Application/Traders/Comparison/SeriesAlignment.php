<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\Data\ReturnPeriodGranularity;
use DateTimeImmutable;

/**
 * How the stored series of one granularity line up across the compared
 * traders (PROJECT.md §15 "clear warning when observation periods differ").
 *
 * - observations: each trader's whole stored series (null = no data), keyed
 *   by trader id in comparison order.
 * - commonFrom/commonTo: the overlap [latest first period, earliest last
 *   period] — only when EVERY trader has data and the windows overlap.
 * - differs: some trader has no data, or the windows (first period, last
 *   period, number of points) are not identical.
 */
final readonly class SeriesAlignment
{
    /**
     * @param  array<int, ObservationPeriod|null>  $observations
     * @param  list<int>  $tradersWithoutData
     */
    public function __construct(
        public ReturnPeriodGranularity $granularity,
        public array $observations,
        public ?DateTimeImmutable $commonFrom,
        public ?DateTimeImmutable $commonTo,
        public bool $differs,
        public array $tradersWithoutData,
    ) {}

    /**
     * @param  array<int, ObservationPeriod|null>  $observations
     */
    public static function of(ReturnPeriodGranularity $granularity, array $observations): self
    {
        $withData = array_filter($observations, static fn (?ObservationPeriod $observation): bool => $observation !== null);
        $without = array_keys(array_diff_key($observations, $withData));

        $differs = $without !== [] && $withData !== [];
        $reference = $withData === [] ? null : reset($withData);

        foreach ($withData as $observation) {
            if ($reference !== null && ! $observation->sameWindowAs($reference)) {
                $differs = true;
            }
        }

        $commonFrom = null;
        $commonTo = null;

        if ($without === [] && $withData !== []) {
            $from = max(array_map(static fn (ObservationPeriod $observation): ?DateTimeImmutable => $observation->from, $withData));
            $to = min(array_map(static fn (ObservationPeriod $observation): ?DateTimeImmutable => $observation->to, $withData));

            if ($from !== null && $to !== null && $from <= $to) {
                [$commonFrom, $commonTo] = [$from, $to];
            }
        }

        return new self($granularity, $observations, $commonFrom, $commonTo, $differs, $without);
    }

    public function hasCommonPeriod(): bool
    {
        return $this->commonFrom !== null;
    }
}
