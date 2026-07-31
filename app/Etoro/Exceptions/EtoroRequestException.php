<?php

namespace App\Etoro\Exceptions;

use App\Etoro\EtoroErrorCategory;
use RuntimeException;

/**
 * A single exception type for all HTTP-level eToro request failures
 * (4xx/5xx, unexpected redirects, and connection failures). Carries only
 * sanitized metadata — never credentials, never the response payload, and
 * never the original transport exception message.
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
        public readonly ?string $transportReason = null,
        public readonly ?int $transportErrno = null,
        public readonly int $attemptCount = 1,
        public readonly ?float $totalDurationMs = null,
        public readonly ?float $finalAttemptDurationMs = null,
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
        int $attemptCount = 1,
        ?float $totalDurationMs = null,
        ?float $finalAttemptDurationMs = null,
    ): self {
        return new self(
            "eToro request failed: {$category->value} (HTTP {$httpStatus}).",
            $category,
            $httpStatus,
            $requestId,
            $retryAfterSeconds,
            $rateLimitLimit,
            $rateLimitRemaining,
            attemptCount: $attemptCount,
            totalDurationMs: $totalDurationMs,
            finalAttemptDurationMs: $finalAttemptDurationMs,
        );
    }

    /**
     * $transportReason is a normalized, pre-approved category (never the
     * original exception message, URL, payload, or credentials) — see
     * EtoroClient::diagnoseTransportFailure().
     */
    public static function connectionFailed(
        ?string $requestId,
        string $transportReason,
        ?int $transportErrno = null,
        int $attemptCount = 1,
        ?float $totalDurationMs = null,
        ?float $finalAttemptDurationMs = null,
    ): self {
        return new self(
            "eToro request failed: connection error after retries ({$transportReason}).",
            EtoroErrorCategory::ConnectionFailed,
            null,
            $requestId,
            transportReason: $transportReason,
            transportErrno: $transportErrno,
            attemptCount: $attemptCount,
            totalDurationMs: $totalDurationMs,
            finalAttemptDurationMs: $finalAttemptDurationMs,
        );
    }
}
