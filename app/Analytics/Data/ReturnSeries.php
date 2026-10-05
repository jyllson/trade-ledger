<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use InvalidArgumentException;

/**
 * An ordered series of period returns at one granularity. Strictly
 * ascending by periodStart with no duplicates; only the first period may
 * be a partial start and only the last may be in progress.
 */
final readonly class ReturnSeries
{
    /**
     * @var list<PeriodReturn>
     */
    public array $periods;

    /**
     * @param  array<int, mixed>  $periods
     */
    public function __construct(
        public ReturnPeriodGranularity $granularity,
        array $periods,
    ) {
        if (! array_is_list($periods)) {
            throw new InvalidArgumentException('ReturnSeries periods must be a list.');
        }

        $lastIndex = count($periods) - 1;
        $previous = null;

        foreach ($periods as $index => $period) {
            if (! $period instanceof PeriodReturn) {
                throw new InvalidArgumentException('ReturnSeries periods must contain only PeriodReturn instances.');
            }

            if ($previous !== null && $period->periodStart <= $previous->periodStart) {
                throw new InvalidArgumentException('ReturnSeries periods must be strictly ascending by periodStart.');
            }

            if ($period->isPartialStart && $index !== 0) {
                throw new InvalidArgumentException('Only the first ReturnSeries period may be a partial start.');
            }

            if ($period->isInProgress && $index !== $lastIndex) {
                throw new InvalidArgumentException('Only the last ReturnSeries period may be in progress.');
            }

            $previous = $period;
        }

        $this->periods = $periods;
    }

    /**
     * @return list<PeriodReturn>
     */
    public function completePeriods(): array
    {
        return array_values(array_filter($this->periods, static fn (PeriodReturn $period): bool => $period->isComplete()));
    }
}
