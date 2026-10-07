<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * Machine-readable CSV export of a TraderComparison (PROJECT.md §20 M5
 * "export to CSV"; docs/DECISIONS.md D-049). One row per value, never an
 * overall score:
 *
 * - `comparison`: methodology, generation instant, thresholds and the
 *   analysis profile;
 * - `observation_period`: each trader's monthly / daily series and latest
 *   snapshot;
 * - `metric`: every §14 metric per trader (status, value, reason,
 *   warnings, observation period);
 * - `profile_filter`: every profile criterion per trader (outcome,
 *   threshold, actual value, explanation);
 * - `profile_filter_summary`: the derived, unweighted D-048 verdict.
 *
 * Values: fractions as decimal fractions (0.25 = 25 %), money as USD
 * decimals with 2 places, timestamps as ISO-8601 UTC ("…Z"); calendar
 * period starts as UTC dates. Text cells starting with a spreadsheet
 * formula trigger are prefixed with an apostrophe (CSV injection), plain
 * numbers are left untouched.
 */
final class TraderComparisonCsv
{
    /** @var list<string> */
    public const array HEADER = [
        'section',
        'dimension',
        'key',
        'label',
        'trader_id',
        'trader_username',
        'status',
        'value',
        'unit',
        'threshold',
        'reason',
        'warnings',
        'note',
        'observation_basis',
        'observation_granularity',
        'observation_from_utc',
        'observation_to_utc',
        'observation_point_count',
        'includes_partial_start_period',
        'includes_in_progress_period',
    ];

    private const string NUMERIC = '/^-?\d+(\.\d+)?$/';

    /** @var list<string> */
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public function render(TraderComparison $comparison): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV export.');
        }

        foreach ($this->rows($comparison) as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function filename(TraderComparison $comparison): string
    {
        return 'trader-comparison-'.$comparison->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd-His').'Z.csv';
    }

    /**
     * @return list<list<string>>
     */
    public function rows(TraderComparison $comparison): array
    {
        $rows = [self::HEADER];
        $profile = $comparison->profile;

        foreach ([
            ['methodology_version', 'Methodology version', $comparison->methodologyVersion, ''],
            ['generated_at', 'Comparison generated at', self::instant($comparison->generatedAt), 'timestamp_utc'],
            ['stale_after_hours', 'Stale after (hours since last successful sync)', (string) $comparison->staleAfterHours, 'hours'],
            ['failed_run_window_days', 'Failed sync runs counted over (days)', (string) $comparison->failedRunWindowDays, 'days'],
            ['observation_periods_differ', 'Observation periods differ between traders', self::bool($comparison->periods->observationPeriodsDiffer()), 'boolean'],
            ['analysis_profile_id', 'Analysis profile id (empty = built-in default)', $profile->profileId === null ? '' : (string) $profile->profileId, ''],
            ['analysis_profile_name', 'Analysis profile', $profile->name, ''],
            ['profile_budget', 'Profile budget', self::usd($profile->budget), 'usd'],
            ['profile_target_coverage', 'Profile target coverage', self::fraction($profile->targetCoverage), 'fraction'],
        ] as [$key, $label, $value, $unit]) {
            $rows[] = self::row(section: 'comparison', key: $key, label: $label, value: $value, unit: $unit);
        }

        foreach ($comparison->entries as $entry) {
            foreach ([
                ['monthly_series', 'Stored monthly performance series', $entry->monthlyObservation],
                ['daily_series', 'Stored daily performance series', $entry->dailyObservation],
                ['latest_snapshot', 'Latest stored portfolio snapshot', $entry->snapshotObservation],
            ] as [$key, $label, $observation]) {
                $rows[] = self::row(
                    section: 'observation_period',
                    key: $key,
                    label: $label,
                    entry: $entry,
                    status: $observation === null ? 'no_data' : 'available',
                    observation: $observation,
                );
            }
        }

        foreach (ComparisonMetricKey::cases() as $key) {
            foreach ($comparison->entries as $entry) {
                $metric = $entry->metric($key);
                [$value, $unit] = self::metricValue($metric);

                $rows[] = self::row(
                    section: 'metric',
                    dimension: $key->dimension()->value,
                    key: $key->value,
                    label: $key->label(),
                    entry: $entry,
                    status: $metric->status->value,
                    value: $value,
                    unit: $unit,
                    reason: $metric->unavailableReason->value ?? '',
                    warnings: implode(';', array_map(static fn (MetricWarning $warning): string => $warning->value, $metric->warnings)),
                    note: $metric->unavailableReason?->label() ?? '',
                    observation: $metric->observation,
                );
            }
        }

        foreach (ProfileCriterion::cases() as $criterion) {
            foreach ($comparison->entries as $entry) {
                $result = $entry->profileFilters?->result($criterion);

                if ($result === null) {
                    continue;
                }

                [$actual, $unit] = self::criterionValue($result->actual, $result->threshold);

                $rows[] = self::row(
                    section: 'profile_filter',
                    key: $criterion->value,
                    label: $criterion->label(),
                    entry: $entry,
                    status: $result->outcome->value,
                    value: $actual,
                    unit: $unit,
                    threshold: self::criterionValue($result->threshold, null)[0],
                    reason: $result->unknownReason->value ?? '',
                    warnings: implode(';', array_map(static fn (MetricWarning $warning): string => $warning->value, $result->warnings)),
                    note: $result->explanation,
                );
            }
        }

        foreach ($comparison->entries as $entry) {
            if ($entry->profileFilters === null) {
                continue;
            }

            $rows[] = self::row(
                section: 'profile_filter_summary',
                key: 'verdict',
                label: 'Derived unweighted summary (not a score)',
                entry: $entry,
                status: $entry->profileFilters->verdict->value,
                note: $entry->profileFilters->verdict->label(),
            );
        }

        return $rows;
    }

    /**
     * Neutralises spreadsheet formulas: a text cell starting with = + - @
     * or a control character gets a leading apostrophe; plain decimal
     * numbers (also negative ones) stay machine-readable.
     */
    public static function cell(string $value): string
    {
        if ($value === '' || preg_match(self::NUMERIC, $value) === 1) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * @return list<string>
     */
    private static function row(
        string $section,
        string $key,
        string $label,
        string $dimension = '',
        ?TraderComparisonEntry $entry = null,
        string $status = '',
        string $value = '',
        string $unit = '',
        string $threshold = '',
        string $reason = '',
        string $warnings = '',
        string $note = '',
        ?ObservationPeriod $observation = null,
    ): array {
        return [
            $section,
            $dimension,
            $key,
            $label,
            $entry === null ? '' : (string) $entry->traderId,
            $entry->username ?? '',
            $status,
            $value,
            $unit,
            $threshold,
            $reason,
            $warnings,
            $note,
            $observation->basis->value ?? '',
            $observation?->granularity->value ?? '',
            self::observationInstant($observation, $observation?->from),
            self::observationInstant($observation, $observation?->to),
            $observation === null ? '' : (string) $observation->pointCount,
            $observation === null ? '' : self::bool($observation->includesPartialStart),
            $observation === null ? '' : self::bool($observation->includesInProgress),
        ];
    }

    /**
     * @return array{string, string} value and unit
     */
    private static function metricValue(ComparisonMetric $metric): array
    {
        $value = $metric->value;
        $unit = match ($metric->key->unit()) {
            MetricUnit::Fraction => 'fraction',
            MetricUnit::Money => 'usd',
            MetricUnit::Count => 'count',
            MetricUnit::Multiple => 'multiple',
            MetricUnit::Timestamp => 'timestamp_utc',
            MetricUnit::Visibility => 'visibility',
            MetricUnit::Flag => 'boolean',
        };

        return [match (true) {
            $value === null => '',
            $value instanceof Percentage => self::fraction($value),
            $value instanceof Money => self::usd($value),
            $value instanceof DateTimeImmutable => self::instant($value),
            is_bool($value) => self::bool($value),
            default => (string) $value,
        }, $unit];
    }

    /**
     * @return array{string, string} value and unit (the threshold decides the unit when the actual is null)
     */
    private static function criterionValue(Percentage|Money|int|null $value, Percentage|Money|int|null $threshold): array
    {
        $typed = $value ?? $threshold;
        $unit = match (true) {
            $typed instanceof Percentage => 'fraction',
            $typed instanceof Money => 'usd',
            is_int($typed) => 'count',
            default => '',
        };

        return [match (true) {
            $value instanceof Percentage => self::fraction($value),
            $value instanceof Money => self::usd($value),
            is_int($value) => (string) $value,
            default => '',
        }, $unit];
    }

    /**
     * Calendar period starts (return series) are UTC dates; every other
     * observation bound is a UTC instant.
     */
    private static function observationInstant(?ObservationPeriod $observation, ?DateTimeImmutable $value): string
    {
        if ($observation === null || $value === null) {
            return '';
        }

        return $observation->basis === ObservationBasis::ReturnSeries
            ? $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
            : self::instant($value);
    }

    private static function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Exact decimal fraction from ppb (9 decimals, 0.25 = 25 %).
     */
    private static function fraction(Percentage $value): string
    {
        return bcdiv((string) $value->partsPerBillion(), '1000000000', 9);
    }

    private static function usd(Money $value): string
    {
        return bcdiv((string) $value->cents(), '100', 2);
    }

    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
