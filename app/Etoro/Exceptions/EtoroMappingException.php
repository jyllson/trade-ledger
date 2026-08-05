<?php

declare(strict_types=1);

namespace App\Etoro\Exceptions;

use RuntimeException;

/**
 * A single exception type for all eToro payload→domain mapping failures
 * (missing/invalid fields, malformed timestamps, unexpected array shapes).
 * Carries only structural metadata — mapper name, a static field path, the
 * reason, and (when relevant) expected/actual *type* names — never the
 * actual value, the full payload, or any identity/free-text data from the
 * response.
 */
final class EtoroMappingException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $mapper,
        public readonly string $fieldPath,
        public readonly EtoroMappingErrorReason $reason,
        public readonly ?string $expectedType = null,
        public readonly ?string $actualType = null,
    ) {
        parent::__construct($message);
    }

    public static function missingRequiredField(string $mapper, string $fieldPath): self
    {
        return new self(
            "{$mapper} could not map {$fieldPath}: required field is missing.",
            $mapper,
            $fieldPath,
            EtoroMappingErrorReason::MissingRequiredField,
        );
    }

    /**
     * $actualType must come from get_debug_type() on the raw value at the
     * call site, never from the value itself, so the message never leaks
     * response content.
     */
    public static function invalidPrimitiveType(string $mapper, string $fieldPath, string $expectedType, string $actualType): self
    {
        return new self(
            "{$mapper} could not map {$fieldPath}: expected {$expectedType}, got {$actualType}.",
            $mapper,
            $fieldPath,
            EtoroMappingErrorReason::InvalidPrimitiveType,
            $expectedType,
            $actualType,
        );
    }

    public static function invalidValue(string $mapper, string $fieldPath): self
    {
        return new self(
            "{$mapper} could not map {$fieldPath}: value is invalid.",
            $mapper,
            $fieldPath,
            EtoroMappingErrorReason::InvalidValue,
        );
    }

    public static function malformedTimestamp(string $mapper, string $fieldPath): self
    {
        return new self(
            "{$mapper} could not map {$fieldPath}: timestamp could not be parsed.",
            $mapper,
            $fieldPath,
            EtoroMappingErrorReason::MalformedTimestamp,
        );
    }

    public static function unexpectedShape(string $mapper, string $fieldPath, string $expectedType, string $actualType): self
    {
        return new self(
            "{$mapper} could not map {$fieldPath}: expected {$expectedType}, got {$actualType}.",
            $mapper,
            $fieldPath,
            EtoroMappingErrorReason::UnexpectedShape,
            $expectedType,
            $actualType,
        );
    }
}
