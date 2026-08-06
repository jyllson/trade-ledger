<?php

declare(strict_types=1);

namespace App\Etoro\Mappers\Support;

use App\Etoro\Exceptions\EtoroMappingException;

/**
 * Normalizes eToro's mixed int/string identifier fields (positionId,
 * instrumentId, cid/gcid, ...) into a single string representation. Range
 * (e.g. positive-only) is deliberately not enforced — no observed API
 * behavior confirms that constraint, so it is not invented here.
 */
final class Identifiers
{
    private function __construct() {}

    public static function normalize(mixed $raw, string $mapper, string $fieldPath): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_string($raw)) {
            if (str_contains($raw, "\0")) {
                throw EtoroMappingException::invalidValue($mapper, $fieldPath);
            }

            // Trim only whitespace, not the default trim() charlist, which
            // also strips "\0" and would silently swallow a leading or
            // trailing null byte instead of letting the check above catch it.
            $trimmed = trim($raw, " \t\n\r\v\f");

            if ($trimmed === '') {
                throw EtoroMappingException::invalidValue($mapper, $fieldPath);
            }

            return $trimmed;
        }

        throw EtoroMappingException::invalidPrimitiveType($mapper, $fieldPath, 'int|string', get_debug_type($raw));
    }
}
