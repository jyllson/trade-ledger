<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Etoro\Data\PerformanceHistory;
use App\Etoro\Data\PerformancePoint;
use App\Etoro\Exceptions\EtoroMappingException;
use DateTimeImmutable;
use DateTimeZone;
use ValueError;

/**
 * Maps a raw eToro `GET /api/v1/user-info/people/{username}/gain` payload
 * (already decoded to a plain array) into a PerformanceHistory domain
 * object. Pure PHP — no HTTP client, no Laravel container/config/storage/
 * logging, no API calls. Never mutates the input array.
 *
 * For each of `monthly` and `yearly` independently: items are mapped in
 * their original order, checked for duplicate periods, and only then
 * sorted deterministically ascending by periodStartedAt — an unsorted but
 * otherwise valid input is accepted and normalized, never rejected for
 * ordering alone.
 */
final class PerformanceHistoryMapper
{
    private const MAPPER_NAME = 'PerformanceHistoryMapper';

    /**
     * Only format supported in this checkpoint — matches the observed
     * eToro shape (e.g. "2010-01-01T00:00:00Z"), identical to
     * LivePortfolioMapper::TIMESTAMP_FORMAT. A future format needs an
     * explicit, reviewed extension, not silent tolerance.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): PerformanceHistory
    {
        return new PerformanceHistory(
            monthly: $this->mapSeries($payload, 'monthly'),
            yearly: $this->mapSeries($payload, 'yearly'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<PerformancePoint>
     */
    private function mapSeries(array $payload, string $field): array
    {
        $rawItems = $this->requireList($payload, $field);

        $points = [];

        foreach ($rawItems as $index => $item) {
            $points[] = $this->mapPoint($item, $field, $index);
        }

        $this->assertNoDuplicatePeriods($points, $field);

        usort($points, fn (PerformancePoint $a, PerformancePoint $b): int => $a->periodStartedAt <=> $b->periodStartedAt);

        return $points;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function requireList(array $payload, string $field): array
    {
        if (! array_key_exists($field, $payload)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $field);
        }

        $value = $payload[$field];

        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $field, 'array', get_debug_type($value));
        }

        if (! array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, $field, 'list', 'associative array');
        }

        return $value;
    }

    private function mapPoint(mixed $item, string $seriesField, int|string $index): PerformancePoint
    {
        $fieldPathPrefix = "{$seriesField}[{$index}]";

        if (! is_array($item)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPathPrefix, 'array', get_debug_type($item));
        }

        return new PerformancePoint(
            periodStartedAt: $this->mapTimestamp($item, $fieldPathPrefix),
            gain: $this->mapGain($item, $fieldPathPrefix),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapTimestamp(array $item, string $fieldPathPrefix): DateTimeImmutable
    {
        $fieldPath = "{$fieldPathPrefix}.timestamp";

        if (! array_key_exists('timestamp', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item['timestamp'];

        if (! is_string($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'string', get_debug_type($raw));
        }

        try {
            $parsed = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $raw, new DateTimeZone('UTC'));
        } catch (ValueError) {
            // createFromFormat() throws ValueError when the decoded string
            // contains a null byte, for example from a JSON Unicode null escape.
            // Never expose the original ValueError message because it may contain
            // source data.
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        // createFromFormat can silently accept a lenient variant (e.g. an
        // unpadded month/day, or a date that rolled over) without raising a
        // warning/error. Re-formatting and comparing against the original
        // string is the only way to catch that.
        if ($parsed->format(self::TIMESTAMP_FORMAT) !== $raw) {
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapGain(array $item, string $fieldPathPrefix): float
    {
        $fieldPath = "{$fieldPathPrefix}.gain";

        if (! array_key_exists('gain', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item['gain'];

        if (! is_int($raw) && ! is_float($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int|float', get_debug_type($raw));
        }

        $value = (float) $raw;

        if (is_nan($value) || is_infinite($value)) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $value;
    }

    /**
     * Detects duplicate periods before sorting, so the reported fieldPath
     * always points at the second occurrence's index in the original
     * (pre-sort) input order — matching what a caller would actually see
     * in the raw payload.
     *
     * @param  list<PerformancePoint>  $points
     */
    private function assertNoDuplicatePeriods(array $points, string $seriesField): void
    {
        $seen = [];

        foreach ($points as $index => $point) {
            $key = $point->periodStartedAt->format(self::TIMESTAMP_FORMAT);

            if (isset($seen[$key])) {
                throw EtoroMappingException::invalidValue(self::MAPPER_NAME, "{$seriesField}[{$index}].timestamp");
            }

            $seen[$key] = true;
        }
    }
}
