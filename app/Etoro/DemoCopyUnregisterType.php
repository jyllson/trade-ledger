<?php

namespace App\Etoro;

/**
 * Documented `unregisterType` values of the demo copy close operation.
 */
enum DemoCopyUnregisterType: string
{
    /** Liquidates the copy's positions and returns the funds. */
    case Close = 'Close';

    /** Releases the positions into the main portfolio as self-managed. */
    case Detach = 'Detach';
}
