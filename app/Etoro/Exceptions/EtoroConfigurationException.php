<?php

namespace App\Etoro\Exceptions;

use RuntimeException;

class EtoroConfigurationException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('eToro integration is disabled (ETORO_ENABLED=false).');
    }

    public static function missingCredential(string $envVariable): self
    {
        return new self("eToro configuration is incomplete: {$envVariable} is not set.");
    }

    /**
     * ETORO_BASE_URL must be a valid absolute URL with an https scheme and a
     * non-empty host — credential headers must never be sent to a
     * non-HTTPS or malformed endpoint. This is not a hard-coded hostname
     * check, so a future demo/staging HTTPS endpoint remains configurable.
     */
    public static function invalidBaseUrl(): self
    {
        return new self('eToro configuration is invalid: ETORO_BASE_URL must be a valid absolute https:// URL.');
    }
}
