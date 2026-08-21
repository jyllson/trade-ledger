<?php

namespace App\Etoro\Exceptions;

use RuntimeException;

/**
 * A 2xx response whose body did not decode into the expected JSON shape, or
 * a disabled/unfollowed redirect. Never carries the raw payload in its
 * message.
 */
class EtoroUnexpectedResponseException extends RuntimeException
{
    /**
     * $attemptCount defaults to 1 for backward compatibility with any
     * existing caller that does not pass it explicitly.
     */
    private function __construct(
        string $message,
        public readonly ?int $httpStatus,
        public readonly ?string $requestId,
        public readonly int $attemptCount = 1,
    ) {
        parent::__construct($message);
    }

    public static function make(?int $httpStatus, ?string $requestId, string $reason, int $attemptCount = 1): self
    {
        return new self("eToro returned an unexpected response: {$reason}", $httpStatus, $requestId, $attemptCount);
    }
}
