<?php

namespace App\Etoro\Exceptions;

use App\Etoro\EtoroErrorCategory;
use RuntimeException;

/**
 * A single exception type for all HTTP-level eToro request failures
 * (4xx/5xx, unexpected redirects, and connection failures). Carries only
 * sanitized metadata — never credentials, never the response payload.
 */
class EtoroRequestException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly EtoroErrorCategory $category,
        public readonly ?int $httpStatus,
        public readonly ?string $requestId,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $rateLimitLimit = null,
        public readonly ?string $rateLimitRemaining = null,
    ) {
        parent::__construct($message);
    }

    public static function fromStatus(
        EtoroErrorCategory $category,
        int $httpStatus,
        ?string $requestId,
        ?int $retryAfterSeconds = null,
        ?string $rateLimitLimit = null,
        ?string $rateLimitRemaining = null,
    ): self {
        return new self(
            "eToro request failed: {$category->value} (HTTP {$httpStatus}).",
            $category,
            $httpStatus,
            $requestId,
            $retryAfterSeconds,
            $rateLimitLimit,
            $rateLimitRemaining,
        );
    }

    public static function connectionFailed(?string $requestId): self
    {
        return new self(
            'eToro request failed: connection error after retries.',
            EtoroErrorCategory::ConnectionFailed,
            null,
            $requestId,
        );
    }
}
