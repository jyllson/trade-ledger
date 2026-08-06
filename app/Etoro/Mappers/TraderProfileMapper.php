<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Etoro\Data\TraderProfile;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\Identifiers;

/**
 * Maps a raw eToro `GET /api/v1/user-info/people` payload (already decoded
 * to a plain array) into a TraderProfile domain object. Pure PHP — no HTTP
 * client, no Laravel container/config/storage/logging, no API calls. Never
 * mutates the input array.
 *
 * Requires `users` to contain exactly one item, matching the existing
 * EtoroClient::userProfile(string $username) single-username contract —
 * batch profile support (multiple usernames in one call) is explicitly out
 * of scope for this checkpoint.
 */
final class TraderProfileMapper
{
    private const MAPPER_NAME = 'TraderProfileMapper';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): TraderProfile
    {
        $usersRaw = $this->requireSingleUserList($payload);

        return $this->mapUser($usersRaw[0]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function requireSingleUserList(array $payload): array
    {
        if (! array_key_exists('users', $payload)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, 'users');
        }

        $value = $payload['users'];

        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, 'users', 'array', get_debug_type($value));
        }

        if (! array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, 'users', 'list', 'associative array');
        }

        if (count($value) !== 1) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, 'users', 'single-item list', 'list');
        }

        return $value;
    }

    private function mapUser(mixed $item): TraderProfile
    {
        $fieldPathPrefix = 'users[0]';

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

        return new TraderProfile(
            gcid: $this->mapGcid($item, $fieldPathPrefix),
            username: $this->mapIdentityString($item, 'username', $fieldPathPrefix),
            isPopularInvestor: $this->mapBool($item, 'isPi', $fieldPathPrefix),
            isVerified: $this->mapBool($item, 'isVerified', $fieldPathPrefix),
            countryCode: $this->mapCountry($item, $fieldPathPrefix),
            languageIsoCode: $this->mapIdentityString($item, 'languageIsoCode', $fieldPathPrefix),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapGcid(array $item, string $fieldPathPrefix): string
    {
        $fieldPath = "{$fieldPathPrefix}.gcid";

        if (! array_key_exists('gcid', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        return Identifiers::normalize($item['gcid'], self::MAPPER_NAME, $fieldPath);
    }

    /**
     * Deliberately does not use Identifiers::normalize() — username and
     * languageIsoCode are never observed as int in this checkpoint's
     * fixture and must not silently accept/coerce one; only a string,
     * trimmed and blank/NUL-checked, is valid.
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
    private function mapBool(array $item, string $field, string $fieldPathPrefix): bool
    {
        $fieldPath = "{$fieldPathPrefix}.{$field}";

        if (! array_key_exists($field, $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item[$field];

        if (! is_bool($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'bool', get_debug_type($raw));
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapCountry(array $item, string $fieldPathPrefix): int
    {
        $fieldPath = "{$fieldPathPrefix}.country";

        if (! array_key_exists('country', $item)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, $fieldPath);
        }

        $raw = $item['country'];

        if (! is_int($raw)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $fieldPath, 'int', get_debug_type($raw));
        }

        return $raw;
    }
}
