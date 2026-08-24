<?php

declare(strict_types=1);

namespace App\Application\Traders;

use InvalidArgumentException;

/**
 * Central, immutable username query contract shared between
 * FindStoredTraderByUsername (local, exact) and LookupEtoroTraderProfile
 * (remote, exact). Exact-match semantics only — no wildcard/LIKE search in
 * this checkpoint.
 */
final readonly class TraderUsername
{
    public string $value;

    public function __construct(string $value)
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('username must not contain a NUL byte.');
        }

        // Whitespace-only charlist, not trim()'s default charlist, which
        // also strips "\0" — the check above is what must catch that case.
        $trimmed = trim($value, " \t\n\r\v\f");

        if ($trimmed === '') {
            throw new InvalidArgumentException('username must not be blank.');
        }

        $this->value = $trimmed;
    }
}
