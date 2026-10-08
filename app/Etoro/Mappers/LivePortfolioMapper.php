<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\Identifiers;
use App\Etoro\Mappers\Support\UtcTimestamp;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Maps a raw eToro `GET /api/v1/user-info/people/{username}/portfolio/live`
 * payload (already decoded to a plain array) into a LivePortfolio domain
 * object. Pure PHP — no HTTP client, no Laravel container/config/storage/
 * logging, no API calls. Never mutates the input array. Timestamps follow
 * the strict formats of UtcTimestamp (docs/DECISIONS.md D-044).
 */
final class LivePortfolioMapper
{
    private const MAPPER_NAME = 'LivePortfolioMapper';

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
            cashWeight: $this->mapCashWeight($payload),
        );
    }

    /**
     * `realizedCreditPct` is the portfolio's cash weight in percentage
     * points: in two live samples Σ investmentPct + realizedCreditPct = 100
     * (docs/DECISIONS.md D-037). Optional — absent means "unknown", never 0.
     *
     * @param  array<string, mixed>  $payload
     */
    private function mapCashWeight(array $payload): ?Percentage
    {
        if (! array_key_exists('realizedCreditPct', $payload) || $payload['realizedCreditPct'] === null) {
            return null;
        }

        $cash = $this->mapInvestmentPct($payload['realizedCreditPct'], 'realizedCreditPct');

        if ($cash->isNegative()) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, 'realizedCreditPct');
        }

        return $cash;
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

        return UtcTimestamp::parse($raw, self::MAPPER_NAME, $fieldPath);
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
