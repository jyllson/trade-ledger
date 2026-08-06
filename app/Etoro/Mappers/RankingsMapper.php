<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\Data\RankingPagination;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\Identifiers;

/**
 * Maps a raw eToro `GET /api/v2/portfolios/rankings` payload (already
 * decoded to a plain array) into a RankingPage domain object. Pure PHP —
 * no HTTP client, no Laravel container/config/storage/logging, no API
 * calls. Never mutates the input array. Never sorts or deduplicates
 * `results` — the mapped entry order is exactly the input order.
 */
final class RankingsMapper
{
    private const MAPPER_NAME = 'RankingsMapper';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): RankingPage
    {
        $resultsRaw = $this->requireResultsList($payload);

        $entries = [];

        foreach ($resultsRaw as $index => $item) {
            $entries[] = $this->mapEntry($item, $index);
        }

        return new RankingPage(
            entries: $entries,
            pagination: $this->mapPagination($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function requireResultsList(array $payload): array
    {
        if (! array_key_exists('results', $payload)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, 'results');
        }

        $value = $payload['results'];

        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, 'results', 'array', get_debug_type($value));
        }

        if (! array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, 'results', 'list', 'associative array');
        }

        return $value;
    }

    private function mapEntry(mixed $item, int|string $index): RankingEntry
    {
        $fieldPathPrefix = "results[{$index}]";

        if (! is_array($item)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPathPrefix, 'array', get_debug_type($item));
        }

        // An empty array cannot be distinguished from an empty JSON object
        // after json_decode(..., true); treat it as an (empty) object and
        // let the first missing-required-field check below report it,
        // rather than misclassifying it as a "list" shape error.
        if ($item !== [] && array_is_list($item)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, $fieldPathPrefix, 'associative array', 'list');
        }

        return new RankingEntry(
            cid: $this->mapCid($item, $fieldPathPrefix),
            username: $this->mapIdentityString($item, 'username', $fieldPathPrefix),
            type: $this->mapIdentityString($item, 'type', $fieldPathPrefix),
            subType: $this->mapIdentityString($item, 'subType', $fieldPathPrefix),
            copiers: $this->mapCopiers($item, $fieldPathPrefix),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapCid(array $item, string $fieldPathPrefix): string
    {
        $fieldPath = "{$fieldPathPrefix}.cid";

        if (! array_key_exists('cid', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        return Identifiers::normalize($item['cid'], self::MAPPER_NAME, $fieldPath);
    }

    /**
     * Deliberately does not use Identifiers::normalize() — username, type,
     * and subType are never observed as int in this checkpoint's fixture,
     * and must not silently accept/coerce one; only a string, trimmed and
     * blank/NUL-checked, is valid.
     *
     * @param  array<string, mixed>  $item
     */
    private function mapIdentityString(array $item, string $field, string $fieldPathPrefix): string
    {
        $fieldPath = "{$fieldPathPrefix}.{$field}";

        if (! array_key_exists($field, $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item[$field];

        if (! is_string($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'string', get_debug_type($raw));
        }

        if (str_contains($raw, "\0")) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        // Whitespace-only charlist, not trim()'s default charlist, which
        // also strips "\0" — the check above is what must catch that case.
        $trimmed = trim($raw, " \t\n\r\v\f");

        if ($trimmed === '') {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapCopiers(array $item, string $fieldPathPrefix): int
    {
        $fieldPath = "{$fieldPathPrefix}.copiers";

        if (! array_key_exists('copiers', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item['copiers'];

        if (! is_int($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int', get_debug_type($raw));
        }

        if ($raw < 0) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mapPagination(array $payload): RankingPagination
    {
        $fieldPath = 'pagination';

        if (! array_key_exists('pagination', $payload)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $value = $payload['pagination'];

        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'object', get_debug_type($value));
        }

        // An empty array cannot be distinguished from an empty JSON object
        // after json_decode(..., true); treat it as an (empty) object and
        // let the first missing-required-field check below report it.
        if ($value !== [] && array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, $fieldPath, 'object', 'list');
        }

        return new RankingPagination(
            page: $this->mapPaginationInt($value, 'page', minimum: 1),
            pageSize: $this->mapPaginationInt($value, 'pageSize', minimum: 0),
            totalItems: $this->mapPaginationInt($value, 'totalItems', minimum: 0),
            hasNext: $this->mapPaginationBool($value, 'hasNext'),
        );
    }

    /**
     * @param  array<string, mixed>  $pagination
     */
    private function mapPaginationInt(array $pagination, string $field, int $minimum): int
    {
        $fieldPath = "pagination.{$field}";

        if (! array_key_exists($field, $pagination)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $pagination[$field];

        if (! is_int($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int', get_debug_type($raw));
        }

        if ($raw < $minimum) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, $fieldPath);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $pagination
     */
    private function mapPaginationBool(array $pagination, string $field): bool
    {
        $fieldPath = "pagination.{$field}";

        if (! array_key_exists($field, $pagination)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $pagination[$field];

        if (! is_bool($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'bool', get_debug_type($raw));
        }

        return $raw;
    }
}
