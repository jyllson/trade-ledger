<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\GainHistory;
use App\Etoro\Data\GainHistoryPoint;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\GainGranularity;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ValueError;

/**
 * Maps a decoded v2 `GET /api/v2/portfolios/{username}/gain/{granularity}`
 * payload into a GainHistory (D-032). Pure PHP — no HTTP, container,
 * config, storage, or logging. Never mutates the input.
 *
 * Fails closed: the response granularity must equal the requested one, so
 * a monthly series can never be stored or analysed as daily. Points are
 * checked for duplicates in original order, then sorted ascending.
 * Unknown extra fields are ignored.
 */
final class GainHistoryMapper
{
    private const MAPPER_NAME = 'GainHistoryMapper';

    private const DATE_FORMAT = '!Y-m-d';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, GainGranularity $expectedGranularity): GainHistory
    {
        $username = $this->requireString($payload, 'username');

        if (trim($username) === '') {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, 'username');
        }

        if ($this->requireString($payload, 'granularity') !== $expectedGranularity->value) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, 'granularity');
        }

        $points = [];

        foreach ($this->requireList($payload, 'gains') as $index => $item) {
            $points[] = $this->mapPoint($item, "gains[{$index}]");
        }

        $this->assertNoDuplicateDates($points);

        usort($points, static fn (GainHistoryPoint $a, GainHistoryPoint $b): int => $a->date <=> $b->date);

        return new GainHistory(
            username: $username,
            granularity: $expectedGranularity,
            totalGain: $this->mapOptionalFraction($payload, 'totalGain'),
            points: $points,
        );
    }

    private function mapPoint(mixed $item, string $fieldPathPrefix): GainHistoryPoint
    {
        if (! is_array($item)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPathPrefix, 'array', get_debug_type($item));
        }

        if (! array_key_exists('gain', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$fieldPathPrefix}.gain");
        }

        return new GainHistoryPoint(
            date: $this->mapDate($item, $fieldPathPrefix),
            gain: $this->mapPeriodGain($item['gain'], "{$fieldPathPrefix}.gain"),
        );
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function mapDate(array $item, string $fieldPathPrefix): DateTimeImmutable
    {
        $fieldPath = "{$fieldPathPrefix}.date";
        $raw = $this->requireString($item, 'date', $fieldPath);

        try {
            $parsed = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $raw, new DateTimeZone('UTC'));
        } catch (ValueError) {
            // Never expose the ValueError message — it may contain source data.
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        // Reject lenient variants (unpadded parts, rolled-over dates).
        if ($parsed->format('Y-m-d') !== $raw) {
            throw EtoroMappingException::malformedTimestamp(self::MAPPER_NAME, $fieldPath);
        }

        return $parsed;
    }

    private function mapFraction(mixed $raw, string $fieldPath): Percentage
    {
        if (! is_int($raw) && ! is_float($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int|float', get_debug_type($raw));
        }

        try {
            return Percentage::fromDecimalFraction($raw);
        } catch (InvalidArgumentException) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }
    }

    /**
     * A single period cannot lose 100% or more and still have a defined
     * equity afterwards — rejected here, naming the field, instead of
     * failing later in the Analytics layer.
     */
    private function mapPeriodGain(mixed $raw, string $fieldPath): Percentage
    {
        $gain = $this->mapFraction($raw, $fieldPath);

        if ($gain->partsPerBillion() <= -1_000_000_000) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $gain;
    }

    /**
     * `totalGain` is documented as nullable (null when `gains` is empty).
     *
     * @param  array<string, mixed>  $payload
     */
    private function mapOptionalFraction(array $payload, string $field): ?Percentage
    {
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        return $this->mapFraction($payload[$field], $field);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function requireString(array $data, string $field, ?string $fieldPath = null): string
    {
        $fieldPath ??= $field;

        if (! array_key_exists($field, $data)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        if (! is_string($data[$field])) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'string', get_debug_type($data[$field]));
        }

        return $data[$field];
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

    /**
     * Reports the second occurrence's original (pre-sort) index.
     *
     * @param  list<GainHistoryPoint>  $points
     */
    private function assertNoDuplicateDates(array $points): void
    {
        $seen = [];

        foreach ($points as $index => $point) {
            $key = $point->date->format('Y-m-d');

            if (isset($seen[$key])) {
                throw EtoroMappingException::invalidValue(self::MAPPER_NAME, "gains[{$index}].date");
            }

            $seen[$key] = true;
        }
    }
}
