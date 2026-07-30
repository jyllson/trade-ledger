<?php

namespace App\Etoro;

use App\Etoro\Exceptions\EtoroWriteModeNotAllowedException;

/**
 * Fail-closed guard for the read-only MVP.
 *
 * Write mode cannot be enabled through configuration: allowsWrite() is
 * hard-coded to false, and ensureReadOnly() refuses to let the application
 * boot when ETORO_ALLOW_WRITE has been switched on by mistake.
 */
class EtoroWriteGuard
{
    public function allowsWrite(): bool
    {
        return false;
    }

    public function ensureReadOnly(): void
    {
        if ((bool) config('etoro.allow_write') !== false) {
            throw EtoroWriteModeNotAllowedException::make();
        }
    }
}
