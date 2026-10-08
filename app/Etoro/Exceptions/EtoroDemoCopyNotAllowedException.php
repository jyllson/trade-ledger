<?php

namespace App\Etoro\Exceptions;

use RuntimeException;

/**
 * Raised by EtoroWriteGuard before a demo copy-trading request is sent.
 * Messages carry only the method and the API path — never credentials,
 * hosts or request bodies.
 */
class EtoroDemoCopyNotAllowedException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('eToro demo copy trading is disabled. Set ETORO_ALLOW_DEMO_COPY=true to enable it (DEMO account only).');
    }

    public static function realAccount(string $method, string $path): self
    {
        return new self("eToro real-account requests are never allowed ({$method} {$path}).");
    }

    public static function untrustedTarget(string $method): self
    {
        return new self("eToro demo copy request target is not the pinned eToro API origin ({$method}); nothing was sent.");
    }

    public static function routeNotAllowListed(string $method, string $path): self
    {
        return new self("eToro request is not on the demo copy allow-list ({$method} {$path}).");
    }
}
