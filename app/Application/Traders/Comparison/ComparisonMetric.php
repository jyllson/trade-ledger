<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One independent comparison value of one trader (docs/DECISIONS.md D-047).
 *
 * Invariant: Unavailable ⇔ value null and a reason; Available/Partial ⇔ a
 * value and no reason. Unknown is therefore never a silent 0. `details`
 * carries supporting figures the UI needs to explain the value (e.g. the
 * known leverage contribution when weighted leverage is not determinable).
 * Every metric — unavailable ones included — has an observation period
 * (ObservationBasis::NoData when nothing is stored), never null.
 */
final readonly class ComparisonMetric
{
    /**
     * @param  Percentage|Money|int|numeric-string|string|bool|DateTimeImmutable|null  $value  typed by key->unit()
     * @param  list<MetricWarning>  $warnings
     * @param  array<string, Percentage|Money|int|string|bool|DateTimeImmutable|array<string, string>|null>  $details
     */
    public function __construct(
        public ComparisonMetricKey $key,
        public MetricStatus $status,
        public Percentage|Money|int|string|bool|DateTimeImmutable|null $value,
        public ?MetricUnavailableReason $unavailableReason,
        public ObservationPeriod $observation,
        public array $warnings = [],
        public array $details = [],
    ) {
        if ($status === MetricStatus::Unavailable) {
            if ($value !== null || $unavailableReason === null) {
                throw new InvalidArgumentException('An unavailable metric must have no value and a reason.');
            }
        } elseif ($value === null || $unavailableReason !== null) {
            throw new InvalidArgumentException('An available or partial metric must have a value and no unavailable reason.');
        }
    }

    /**
     * @param  Percentage|Money|int|numeric-string|string|bool|DateTimeImmutable  $value
     * @param  list<MetricWarning>  $warnings
     * @param  array<string, Percentage|Money|int|string|bool|DateTimeImmutable|array<string, string>|null>  $details
     */
    public static function available(
        ComparisonMetricKey $key,
        Percentage|Money|int|string|bool|DateTimeImmutable $value,
        ObservationPeriod $observation,
        array $warnings = [],
        array $details = [],
        bool $partial = false,
    ): self {
        return new self($key, $partial ? MetricStatus::Partial : MetricStatus::Available, $value, null, $observation, $warnings, $details);
    }

    /**
     * @param  list<MetricWarning>  $warnings
     * @param  array<string, Percentage|Money|int|string|bool|DateTimeImmutable|array<string, string>|null>  $details
     */
    public static function unavailable(
        ComparisonMetricKey $key,
        MetricUnavailableReason $reason,
        ObservationPeriod $observation,
        array $warnings = [],
        array $details = [],
    ): self {
        return new self($key, MetricStatus::Unavailable, null, $reason, $observation, $warnings, $details);
    }

    public function isAvailable(): bool
    {
        return $this->status !== MetricStatus::Unavailable;
    }
}
