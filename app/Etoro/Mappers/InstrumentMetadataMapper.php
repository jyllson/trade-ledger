<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Etoro\Data\InstrumentDisplayData;
use App\Etoro\Data\InstrumentType;
use App\Etoro\Exceptions\EtoroMappingException;

/**
 * Maps the market-data instrument display and instrument type payloads
 * (D-037). Pure — no HTTP, container, or I/O; never mutates input.
 *
 * Optional display fields that are missing, null, or of an unexpected type
 * become null (metadata is enrichment, never a reason to drop a row); the
 * identifying `instrumentID` is required and must be a positive int. The
 * live API returns `stocksIndustryID` while the OpenAPI document says
 * `stocksIndustryId` — both spellings are accepted.
 */
final class InstrumentMetadataMapper
{
    private const MAPPER_NAME = 'InstrumentMetadataMapper';

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InstrumentDisplayData>
     */
    public function mapDisplayData(array $payload): array
    {
        $rows = [];

        foreach ($this->requireList($payload, 'instrumentDisplayDatas') as $index => $item) {
            $path = "instrumentDisplayDatas[{$index}]";

            if (! is_array($item)) {
                throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $path, 'array', get_debug_type($item));
            }

            $rows[] = new InstrumentDisplayData(
                instrumentId: $this->requirePositiveInt($item, 'instrumentID', "{$path}.instrumentID"),
                displayName: $this->optionalString($item, 'instrumentDisplayName'),
                symbolFull: $this->optionalString($item, 'symbolFull'),
                instrumentTypeId: $this->optionalInt($item, 'instrumentTypeID'),
                exchangeId: $this->optionalInt($item, 'exchangeID'),
                stocksIndustryId: $this->optionalInt($item, 'stocksIndustryID') ?? $this->optionalInt($item, 'stocksIndustryId'),
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InstrumentType>
     */
    public function mapInstrumentTypes(array $payload): array
    {
        $types = [];

        foreach ($this->requireList($payload, 'instrumentTypes') as $index => $item) {
            $path = "instrumentTypes[{$index}]";

            if (! is_array($item)) {
                throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $path, 'array', get_debug_type($item));
            }

            $description = $this->optionalString($item, 'instrumentTypeDescription');

            if ($description === null) {
                throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$path}.instrumentTypeDescription");
            }

            $types[] = new InstrumentType($this->requirePositiveInt($item, 'instrumentTypeID', "{$path}.instrumentTypeID"), $description);
        }

        return $types;
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

        if (! is_array($payload[$field])) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $field, 'array', get_debug_type($payload[$field]));
        }

        if (! array_is_list($payload[$field])) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, $field, 'list', 'associative array');
        }

        return $payload[$field];
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function requirePositiveInt(array $item, string $field, string $path): int
    {
        if (! array_key_exists($field, $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $path);
        }

        if (! is_int($item[$field])) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $path, 'int', get_debug_type($item[$field]));
        }

        if ($item[$field] < 1) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $path);
        }

        return $item[$field];
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function optionalString(array $item, string $field): ?string
    {
        $value = $item[$field] ?? null;

        return is_string($value) && trim($value) !== '' && ! str_contains($value, "\0") ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function optionalInt(array $item, string $field): ?int
    {
        $value = $item[$field] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }
}
