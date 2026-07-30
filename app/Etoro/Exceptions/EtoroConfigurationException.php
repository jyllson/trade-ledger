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
}
