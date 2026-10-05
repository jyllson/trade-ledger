<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Outcome of the last performance sync that reached the API. A 403 from the
 * v2 gain endpoint is documented as "target user has opted out of portfolio
 * exposure" — a data state, not an error to retry (PROJECT.md §8, §16).
 */
enum PerformanceVisibility: string
{
    case Available = 'available';
    case Private = 'private';
    case NotFound = 'not_found';
}
