<?php

namespace App\Etoro\Exceptions;

use RuntimeException;

/**
 * A 2xx response whose body did not decode into the expected JSON shape.
 * Never carries the raw payload in its message.
 */
class EtoroUnexpectedResponseException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?int $httpStatus,
        public readonly ?string $requestId,
    ) {
        parent::__construct($message);
    }

    public static function make(?int $httpStatus, ?string $requestId, string $reason): self
    {
        return new self("eToro returned an unexpected response: {$reason}", $httpStatus, $requestId);
    }
}
