<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\Data\PeriodReturn;
use App\Analytics\Data\ReturnPeriodGranularity;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The data a metric (or a trader's series) was computed from — every
 * comparison metric carries one, so differing periods are always visible
 * (PROJECT.md §15/§20 M5; docs/DECISIONS.md D-047). See ObservationBasis
 * for the meaning of from/to/pointCount per basis. Period starts are UTC
 * calendar dates (D-046 point 3); timestamps are UTC instants.
 */
final readonly class ObservationPeriod
{
    public function __construct(
        public ObservationBasis $basis,
        public ?ReturnPeriodGranularity $granularity,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public int $pointCount,
        public bool $includesPartialStart = false,
        public bool $includesInProgress = false,
    ) {}

    /**
     * Explicit empty period: nothing stored to observe, evaluated at
     * `$evaluatedAt` (ObservationBasis::NoData).
     */
    public static function noData(DateTimeImmutable $evaluatedAt): self
    {
        return new self(ObservationBasis::NoData, null, null, $evaluatedAt, 0);
    }

    /**
     * A state evaluated at the comparison instant (ObservationBasis::EvaluatedAt).
     */
    public static function evaluatedAt(DateTimeImmutable $instant): self
    {
        return new self(ObservationBasis::EvaluatedAt, null, null, $instant, 1);
    }

    /**
     * A timestamp kept on the trader row (ObservationBasis::SyncRecord).
     */
    public static function syncRecord(DateTimeImmutable $syncedAt): self
    {
        return new self(ObservationBasis::SyncRecord, null, null, $syncedAt, 1);
    }

    public function hasData(): bool
    {
        return $this->basis !== ObservationBasis::NoData;
    }

    /**
     * @param  list<PeriodReturn>  $periods  at least one period
     */
    public static function ofPeriods(ReturnPeriodGranularity $granularity, array $periods): self
    {
        if ($periods === []) {
            throw new InvalidArgumentException('An observation period needs at least one period.');
        }

        $last = $periods[count($periods) - 1];

        return new self(
            basis: ObservationBasis::ReturnSeries,
            granularity: $granularity,
            from: $periods[0]->periodStart,
            to: $last->periodStart,
            pointCount: count($periods),
            includesPartialStart: $periods[0]->isPartialStart,
            includesInProgress: $last->isInProgress,
        );
    }

    public function sameWindowAs(self $other): bool
    {
        return $this->basis === $other->basis
            && $this->granularity === $other->granularity
            && $this->from == $other->from
            && $this->to == $other->to
            && $this->pointCount === $other->pointCount;
    }
}
