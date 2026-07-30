<?php

namespace App\Etoro\Exceptions;

use RuntimeException;

class EtoroWriteModeNotAllowedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'eToro write mode is permanently disabled during the MVP. '
            .'This application is read-only analytics by design; remove ETORO_ALLOW_WRITE=true from the environment.'
        );
    }
}
