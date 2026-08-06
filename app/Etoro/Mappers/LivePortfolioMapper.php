<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\Identifiers;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ValueError;

/**
 * Maps a raw eToro `GET /api/v1/user-info/people/{username}/portfolio/live`
 * payload (already decoded to a plain array) into a LivePortfolio domain
 * object. Pure PHP — no HTTP client, no Laravel container/config/storage/
 * logging, no API calls. Never mutates the input array.
 */
final class LivePortfolioMapper
{
    private const MAPPER_NAME = 'LivePortfolioMapper';

    /**
     * Only format supported in this checkpoint — matches the observed
     * eToro shape (e.g. "2012-01-10T09:15:00Z"). A future format needs an
     * explicit, reviewed extension, not silent tolerance.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): LivePortfolio
    {
        $positionsRaw = $this->requireList($payload, 'positions');
        $socialTradesRaw = $this->requireList($payload, 'socialTrades');

        $positions = [];

        foreach ($positionsRaw as $index => $item) {
            $positions[] = $this->mapPosition($item, $index);
        }

        return new LivePortfolio(
            positions: $positions,
            socialTradesCount: count($socialTradesRaw),
        );
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

    private function mapPosition(mixed $item, int|string $index): PortfolioPosition
    {
        $fieldPathPrefix = "positions[{$index}]";

        if (! is_array($item)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPathPrefix, 'array', get_debug_type($item));
        }

        $positionId = Identifiers::normalize(
            $this->requireField($item, 'positionId', $fieldPathPrefix),
            self::MAPPER_NAME,
            "{$fieldPathPrefix}.positionId",
        );

        $instrumentId = Identifiers::normalize(
            $this->requireField($item, 'instrumentId', $fieldPathPrefix),
            self::MAPPER_NAME,
            "{$fieldPathPrefix}.instrumentId",
        );

        $weight = $this->mapInvestmentPct(
            $this->requireField($item, 'investmentPct', $fieldPathPrefix),
            "{$fieldPathPrefix}.investmentPct",
        );

        return new PortfolioPosition(
            positionId: $positionId,
            instrumentId: $instrumentId,
            weight: $weight,
            openedAt: $this->mapOptionalTimestamp($item, 'openTimestamp', $fieldPathPrefix),
            isBuy: $this->mapOptionalBool($item, 'isBuy', $fieldPathPrefix),
            leverage: $this->mapOptionalInt($item, 'leverage', $fieldPathPrefix),
            takeProfitRate: $this->mapOptionalRate($item, 'takeProfitRate', $fieldPathPrefix),
            stopLossRate: $this->mapOptionalRate($item, 'stopLossRate', $fieldPathPrefix),
            trailingStopLoss: $this->mapOptionalBool($item, 'trailingStopLoss', $fieldPathPrefix),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function requireField(array $item, string $field, string $fieldPathPrefix): mixed
    {
        if (! array_key_exists($field, $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$fieldPathPrefix}.{$field}");
        }

        return $item[$field];
    }

    private function mapInvestmentPct(mixed $raw, string $fieldPath): Percentage
    {
        if (! is_int($raw) && ! is_float($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int|float', get_debug_type($raw));
        }

        try {
            return Percentage::fromEtoroInvestmentPct($raw);
        } catch (InvalidArgumentException) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapOptionalTimestamp(array $item, string $field, string $fieldPathPrefix): ?DateTimeImmutable
    {
        if (! array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        $raw = $item[$field];
        $fieldPath = "{$fieldPathPrefix}.{$field}";

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
    private function mapOptionalBool(array $item, string $field, string $fieldPathPrefix): ?bool
    {
        if (! array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        $raw = $item[$field];

        if (! is_bool($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$fieldPathPrefix}.{$field}", 'bool', get_debug_type($raw));
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapOptionalInt(array $item, string $field, string $fieldPathPrefix): ?int
    {
        if (! array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        $raw = $item[$field];

        if (! is_int($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$fieldPathPrefix}.{$field}", 'int', get_debug_type($raw));
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapOptionalRate(array $item, string $field, string $fieldPathPrefix): ?float
    {
        if (! array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        $raw = $item[$field];
        $fieldPath = "{$fieldPathPrefix}.{$field}";

        if (! is_int($raw) && ! is_float($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int|float', get_debug_type($raw));
        }

        $value = (float) $raw;

        if (is_nan($value) || is_infinite($value)) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $value;
    }
}
