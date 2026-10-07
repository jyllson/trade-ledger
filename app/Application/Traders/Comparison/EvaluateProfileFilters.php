<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\AnalysisProfiles\AnalysisProfileCriteria;
use App\Application\AnalysisProfiles\AnalysisProfileSettings;
use InvalidArgumentException;
use LogicException;

/**
 * Transparent analysis profile filters (PROJECT.md §15 filters, §20 M5;
 * docs/DECISIONS.md D-048). Pure: reads only the profile values and one
 * comparison entry — no Eloquent, no database.
 *
 * Per criterion: the profile threshold, the trader's actual value, the
 * outcome and an explanation. A null threshold is NotApplied; an
 * unavailable or partial metric is Unknown — never a silent pass or fail;
 * equality with the threshold passes ("at most" / "at least"). The
 * derived verdict is unweighted (ProfileFilterResult) — no score.
 */
final class EvaluateProfileFilters
{
    /**
     * @throws InvalidArgumentException when the entry's profile-budget metrics were built for another budget or target
     */
    public function evaluate(AnalysisProfileCriteria $profile, TraderComparisonEntry $entry): ProfileFilterResult
    {
        $this->assertBuiltForProfile($profile, $entry);

        return ProfileFilterResult::of($profile->profileId, [
            $this->targetCoverage($profile, $entry),
            $this->drawdown($profile->maximumDrawdown, $entry),
            $this->threshold(ProfileCriterion::MaximumRiskScore, $profile->maximumRiskScore, $entry->metric(ComparisonMetricKey::RiskScore), maximum: true, subject: 'Risk score'),
            $this->threshold(ProfileCriterion::MaximumSinglePosition, $profile->maximumSinglePosition, $entry->metric(ComparisonMetricKey::LargestPosition), maximum: true, subject: 'Largest position (by instrument, invested weight)'),
            $this->history($profile->minimumHistoryMonths, $entry),
            $this->threshold(ProfileCriterion::MinimumPositiveMonths, $profile->minimumPositiveMonths, $entry->metric(ComparisonMetricKey::PositiveMonthRatio), maximum: false, subject: 'Positive-month ratio (complete months)'),
            $this->allocation($profile),
        ]);
    }

    /**
     * Copyability: the coverage at the profile budget (relative to the
     * visible positive position weight, D-022) must reach the target.
     * No positive weight in a complete snapshot is a known fact — the
     * target is unreachable — so it fails; in an estimated (incomplete)
     * snapshot it is not, so it is unknown like no snapshot / out of range.
     */
    private function targetCoverage(AnalysisProfileCriteria $profile, TraderComparisonEntry $entry): CriterionResult
    {
        $criterion = ProfileCriterion::TargetCoverageAtBudget;
        $coverage = $entry->metric(ComparisonMetricKey::CoverageAtProfileBudget);
        $minimum = $entry->metric(ComparisonMetricKey::MinimumForProfileTarget);
        $target = $profile->targetCoverage;
        $budget = self::money($profile->budget);
        $details = [
            'budget' => $profile->budget,
            'minimum_for_target' => $minimum->isAvailable() && $minimum->value instanceof Money ? $minimum->value : null,
        ];
        $warnings = $coverage->warnings;

        if ($coverage->unavailableReason === MetricUnavailableReason::NoPositiveWeight
            && in_array(MetricWarning::EstimatedFromIncompleteSnapshot, $warnings, true)) {
            return new CriterionResult(
                $criterion,
                CriterionOutcome::Unknown,
                $target,
                null,
                sprintf('No positive position weight was found, but the snapshot is incomplete (estimated), so coverage at %s is not known — neither pass nor fail.', $budget),
                $criterion->metricKeys(),
                $warnings,
                CriterionUnknownReason::MetricPartial,
                [...$details, 'metric_unavailable_reason' => $coverage->unavailableReason->value],
            );
        }

        if ($coverage->unavailableReason === MetricUnavailableReason::NoPositiveWeight) {
            return new CriterionResult(
                $criterion,
                CriterionOutcome::Fail,
                $target,
                null,
                sprintf('The latest snapshot has no positive position weight: nothing can be copied at %s, so the %s target is unreachable.', $budget, self::percent($target)),
                $criterion->metricKeys(),
                $warnings,
                details: $details,
            );
        }

        $unknown = $this->unknown($criterion, $target, $coverage, sprintf('Coverage at %s', $budget), $details);

        if ($unknown !== null) {
            return $unknown;
        }

        $actual = self::percentage($coverage);
        $passes = $actual->compareTo($target) >= 0;
        $minimumText = $details['minimum_for_target'] instanceof Money ? sprintf(' (minimum for the target: %s)', self::money($details['minimum_for_target'])) : '';

        return new CriterionResult(
            $criterion,
            $passes ? CriterionOutcome::Pass : CriterionOutcome::Fail,
            $target,
            $actual,
            sprintf(
                'Coverage at %s is %s of the visible positive position weight, %s the %s target%s.',
                $budget,
                self::percent($actual),
                $passes ? 'reaching' : 'below',
                self::percent($target),
                $minimumText,
            ),
            $criterion->metricKeys(),
            $warnings,
            details: $details,
        );
    }

    /**
     * Both the daily and the monthly max drawdown must be within the
     * maximum: the daily series is finer but usually shorter, the monthly
     * longer but blind to intra-month drops, so neither alone bounds the
     * worst observed drop. Any available one above the maximum fails; pass
     * needs both available; otherwise unknown. Actual = the worse known one.
     */
    private function drawdown(?Percentage $maximum, TraderComparisonEntry $entry): CriterionResult
    {
        $criterion = ProfileCriterion::MaximumDrawdown;

        if ($maximum === null) {
            return $this->notApplied($criterion);
        }

        $metrics = [
            'daily' => $entry->metric(ComparisonMetricKey::DailyMaxDrawdown),
            'monthly' => $entry->metric(ComparisonMetricKey::MonthlyMaxDrawdown),
        ];
        $known = [];
        $details = [];
        $warnings = [];

        foreach ($metrics as $granularity => $metric) {
            $warnings = [...$warnings, ...$metric->warnings];
            $details[$granularity.'_max_drawdown'] = $metric->status === MetricStatus::Available ? self::percentage($metric) : null;
            $details[$granularity.'_status'] = $metric->status->value;

            if ($metric->status === MetricStatus::Available) {
                $known[$granularity] = self::percentage($metric);
            }
        }

        $warnings = array_values(array_unique($warnings, SORT_REGULAR));
        $worst = null;

        foreach ($known as $value) {
            $worst = $worst === null || $value->compareTo($worst) > 0 ? $value : $worst;
        }

        $failing = array_keys(array_filter($known, static fn (Percentage $value): bool => $value->compareTo($maximum) > 0));

        if ($failing !== []) {
            return new CriterionResult(
                $criterion,
                CriterionOutcome::Fail,
                $maximum,
                $worst,
                sprintf('Max drawdown (%s) of %s exceeds the %s maximum.', implode(', ', $failing), self::percent(self::required($worst)), self::percent($maximum)),
                $criterion->metricKeys(),
                $warnings,
                details: $details,
            );
        }

        if (count($known) < count($metrics)) {
            $missing = array_keys(array_diff_key($metrics, $known));

            return new CriterionResult(
                $criterion,
                CriterionOutcome::Unknown,
                $maximum,
                $worst,
                sprintf(
                    'The %s max drawdown is not available%s, so the %s maximum cannot be confirmed.',
                    implode(' and ', $missing),
                    $worst === null ? '' : sprintf(' (the known one, %s, is within it)', self::percent($worst)),
                    self::percent($maximum),
                ),
                $criterion->metricKeys(),
                $warnings,
                $metrics[$missing[0]]->isAvailable() ? CriterionUnknownReason::MetricPartial : CriterionUnknownReason::MetricUnavailable,
                $details,
            );
        }

        return new CriterionResult(
            $criterion,
            CriterionOutcome::Pass,
            $maximum,
            $worst,
            sprintf('Daily and monthly max drawdown are at most %s, within the %s maximum.', self::percent(self::required($worst)), self::percent($maximum)),
            $criterion->metricKeys(),
            $warnings,
            details: $details,
        );
    }

    /**
     * History = number of COMPLETE calendar months of the stored monthly
     * series (partial first month and the month in progress excluded,
     * D-033) — the positive-month ratio's observation. A series without a
     * complete month is a known 0; no stored series is unknown.
     */
    private function history(?int $minimum, TraderComparisonEntry $entry): CriterionResult
    {
        $criterion = ProfileCriterion::MinimumHistoryMonths;

        if ($minimum === null) {
            return $this->notApplied($criterion);
        }

        $metric = $entry->metric(ComparisonMetricKey::PositiveMonthRatio);
        $months = match (true) {
            $metric->isAvailable() => $metric->observation->pointCount,
            $metric->unavailableReason === MetricUnavailableReason::InsufficientHistory => is_int($metric->details['available_complete_periods'] ?? null) ? $metric->details['available_complete_periods'] : null,
            default => null,
        };

        if ($months === null) {
            return $this->unknown($criterion, $minimum, $metric, 'History') ?? throw new LogicException('An unavailable metric is always unknown.');
        }

        $passes = $months >= $minimum;

        return new CriterionResult(
            $criterion,
            $passes ? CriterionOutcome::Pass : CriterionOutcome::Fail,
            $minimum,
            $months,
            sprintf('%d complete month%s of history, %s the minimum of %d.', $months, $months === 1 ? '' : 's', $passes ? 'at least' : 'below', $minimum),
            $criterion->metricKeys(),
            $metric->warnings,
            details: ['basis' => 'complete_monthly_periods'],
        );
    }

    /**
     * A portfolio-construction limit of the user (share of the budget per
     * trader), not a property of the trader — shown, never compared.
     */
    private function allocation(AnalysisProfileCriteria $profile): CriterionResult
    {
        $criterion = ProfileCriterion::MaximumAllocationPerTrader;
        $share = $profile->maximumAllocationPerTrader;

        if ($share === null) {
            return $this->notApplied($criterion);
        }

        // floor(budget × share), exact in integer arithmetic (share ≤ 10⁹ ppb, budget ≤ 10⁹ cents).
        $amount = Money::fromCents(intdiv($profile->budget->cents() * $share->partsPerBillion(), AnalysisProfileSettings::WHOLE_PPB));

        return new CriterionResult(
            $criterion,
            CriterionOutcome::Informational,
            $share,
            null,
            sprintf(
                'At most %s of the %s budget (%s) per trader. A limit on how the budget is split, not a property of the trader — informational, not evaluated.',
                self::percent($share),
                self::money($profile->budget),
                self::money($amount),
            ),
            details: ['allocation_amount' => $amount, 'budget' => $profile->budget],
        );
    }

    private function threshold(ProfileCriterion $criterion, Percentage|int|null $threshold, ComparisonMetric $metric, bool $maximum, string $subject): CriterionResult
    {
        if ($threshold === null) {
            return $this->notApplied($criterion);
        }

        $unknown = $this->unknown($criterion, $threshold, $metric, $subject);

        if ($unknown !== null) {
            return $unknown;
        }

        $actual = $metric->value;

        if ($threshold instanceof Percentage) {
            $actual = self::percentage($metric);
            $comparison = $actual->compareTo($threshold);
        } elseif (is_int($actual)) {
            $comparison = $actual <=> $threshold;
        } else {
            throw new LogicException(sprintf('Metric "%s" has no value comparable with the threshold.', $metric->key->value));
        }

        $passes = $maximum ? $comparison <= 0 : $comparison >= 0;
        $relation = match (true) {
            $maximum && $passes => 'within the',
            $maximum => 'above the',
            $passes => 'at least the',
            default => 'below the',
        };

        return new CriterionResult(
            $criterion,
            $passes ? CriterionOutcome::Pass : CriterionOutcome::Fail,
            $threshold,
            $actual,
            sprintf('%s is %s, %s %s %s.', $subject, self::format($actual), $relation, self::format($threshold), $maximum ? 'maximum' : 'minimum'),
            $criterion->metricKeys(),
            $metric->warnings,
        );
    }

    /**
     * Unknown for an unavailable or partial metric; null when the metric
     * is fully available.
     *
     * @param  array<string, Percentage|Money|int|string|bool|null>  $details
     */
    private function unknown(ProfileCriterion $criterion, Percentage|Money|int $threshold, ComparisonMetric $metric, string $subject, array $details = []): ?CriterionResult
    {
        if (! $metric->isAvailable()) {
            return new CriterionResult(
                $criterion,
                CriterionOutcome::Unknown,
                $threshold,
                null,
                sprintf('%s is not available (%s) — neither pass nor fail.', $subject, $metric->unavailableReason?->value),
                $criterion->metricKeys(),
                $metric->warnings,
                CriterionUnknownReason::MetricUnavailable,
                [...$details, 'metric_unavailable_reason' => $metric->unavailableReason?->value],
            );
        }

        if ($metric->status === MetricStatus::Partial) {
            $actual = $metric->value instanceof Percentage || $metric->value instanceof Money || is_int($metric->value) ? $metric->value : null;

            return new CriterionResult(
                $criterion,
                CriterionOutcome::Unknown,
                $threshold,
                $actual,
                sprintf('%s%s is only partially determined (part of the input is unknown or estimated) — neither pass nor fail.', $subject, $actual === null ? '' : ' ('.self::format($actual).')'),
                $criterion->metricKeys(),
                $metric->warnings,
                CriterionUnknownReason::MetricPartial,
                $details,
            );
        }

        return null;
    }

    private function notApplied(ProfileCriterion $criterion): CriterionResult
    {
        return new CriterionResult(
            $criterion,
            CriterionOutcome::NotApplied,
            null,
            null,
            'Not applied: the profile leaves this criterion empty.',
            $criterion->metricKeys(),
        );
    }

    /**
     * The profile-budget metrics carry the budget and target they were
     * built for; evaluating them against another profile would compare the
     * wrong amount.
     */
    private function assertBuiltForProfile(AnalysisProfileCriteria $profile, TraderComparisonEntry $entry): void
    {
        $amount = $entry->metric(ComparisonMetricKey::CoverageAtProfileBudget)->details['copy_amount'] ?? null;
        $target = $entry->metric(ComparisonMetricKey::MinimumForProfileTarget)->details['target_coverage'] ?? null;

        if (($amount instanceof Money && $amount->compareTo($profile->budget) !== 0)
            || ($target instanceof Percentage && $target->compareTo($profile->targetCoverage) !== 0)) {
            throw new InvalidArgumentException('The comparison entry was built for a different profile budget or target.');
        }
    }

    private static function percentage(ComparisonMetric $metric): Percentage
    {
        return $metric->value instanceof Percentage
            ? $metric->value
            : throw new LogicException(sprintf('Metric "%s" is not a fraction.', $metric->key->value));
    }

    private static function required(?Percentage $value): Percentage
    {
        return $value ?? throw new LogicException('A known value was expected.');
    }

    private static function format(Percentage|Money|int $value): string
    {
        return match (true) {
            $value instanceof Percentage => self::percent($value),
            $value instanceof Money => self::money($value),
            default => (string) $value,
        };
    }

    /**
     * Exact percent, trailing zeros trimmed ("95%", "99.8992443%") — a
     * rounded figure could show equal numbers for a failed comparison.
     */
    private static function percent(Percentage $value): string
    {
        $percent = bcdiv((string) $value->partsPerBillion(), '10000000', 7);

        return rtrim(rtrim($percent, '0'), '.').'%';
    }

    private static function money(Money $value): string
    {
        $cents = $value->cents();
        $text = '$'.number_format(intdiv(abs($cents), 100));

        if (abs($cents) % 100 !== 0) {
            $text .= sprintf('.%02d', abs($cents) % 100);
        }

        return ($cents < 0 ? '-' : '').$text;
    }
}
