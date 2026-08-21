<?php

declare(strict_types=1);

namespace App\Application\Imports;

use RuntimeException;

/**
 * Sanitized, fully static domain exception for
 * RetryEtoroTraderDiscovery's fail-closed eligibility gate. Never carries
 * the original ImportRun's metadata, query values, or any other dynamic
 * content — only a fixed, pre-approved reason category per factory method.
 */
final class ImportRunNotRetryableException extends RuntimeException
{
    public static function notPersisted(): self
    {
        return new self('Import run is not eligible for retry: run is not persisted.');
    }

    public static function wrongSource(): self
    {
        return new self('Import run is not eligible for retry: source is not etoro.');
    }

    public static function wrongType(): self
    {
        return new self('Import run is not eligible for retry: type is not rankings_discovery.');
    }

    public static function wrongStatus(): self
    {
        return new self('Import run is not eligible for retry: status is not failed or partial.');
    }

    public static function notMarkedRetryable(): self
    {
        return new self('Import run is not eligible for retry: not marked retryable.');
    }

    public static function notYetEligible(): self
    {
        return new self('Import run is not eligible for retry: retry_not_before has not yet elapsed.');
    }

    public static function malformedQueryMetadata(): self
    {
        return new self('Import run is not eligible for retry: query metadata is missing or malformed.');
    }
}
