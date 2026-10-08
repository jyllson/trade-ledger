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
     * ETORO_BASE_URL must be a bare https origin (no userinfo, port other
     * than 443, path, query or fragment) — credential headers must never be
     * sent to a non-HTTPS, malformed or path-shifted endpoint. Demo copy
     * additionally pins the host to public-api.etoro.com (D-050).
     */
    public static function invalidBaseUrl(): self
    {
        return new self('eToro configuration is invalid: ETORO_BASE_URL must be a bare https:// origin (no path, query, fragment, userinfo or non-443 port).');
    }
}
