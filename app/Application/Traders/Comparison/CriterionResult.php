<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * One analysis profile criterion evaluated for one trader (D-048):
 * the profile's threshold, the trader's actual value, the outcome and a
 * short explanation.
 *
 * Invariants: NotApplied ⇔ no threshold; Unknown ⇔ an unknown reason (the
 * actual value may be present when the metric is only partial); Pass
 * always has an actual value. Fail has one too, except the known fact
 * "no positive position weight" — the target is unreachable, but coverage
 * of a zero weight has no value (never a made-up 0). Informational has a
 * threshold but is never compared.
 */
final readonly class CriterionResult
{
    /**
     * @param  list<ComparisonMetricKey>  $metricKeys  the metrics the criterion read
     * @param  list<MetricWarning>  $warnings  the warnings of those metrics
     * @param  array<string, Percentage|Money|int|string|bool|null>  $details
     */
    public function __construct(
        public ProfileCriterion $criterion,
        public CriterionOutcome $outcome,
        public Percentage|Money|int|null $threshold,
        public Percentage|Money|int|null $actual,
        public string $explanation,
        public array $metricKeys = [],
        public array $warnings = [],
        public ?CriterionUnknownReason $unknownReason = null,
        public array $details = [],
    ) {
        if (($outcome === CriterionOutcome::NotApplied) !== ($threshold === null)) {
            throw new InvalidArgumentException('Exactly the criteria that are not applied have no threshold.');
        }

        if (($outcome === CriterionOutcome::Unknown) !== ($unknownReason !== null)) {
            throw new InvalidArgumentException('Exactly the unknown criteria have an unknown reason.');
        }

        if ($outcome === CriterionOutcome::Pass && $actual === null) {
            throw new InvalidArgumentException('A passed criterion must have an actual value.');
        }
    }
}
