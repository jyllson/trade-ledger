<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\Trader;

/**
 * Local, exact, read-only lookup of an already-imported Trader by username.
 * No HTTP, no ImportRun, no mutation.
 */
final class FindStoredTraderByUsername
{
    public function handle(TraderUsername $username): ?Trader
    {
        $trader = Trader::query()->where('username', $username->value)->first();

        if ($trader === null) {
            return null;
        }

        // Fail-closed against a case-insensitive DB collation match (e.g.
        // MySQL's default utf8mb4_unicode_ci — see docs/DECISIONS.md D-025
        // for the same concern in a different context): only ever return a
        // row whose stored username is PHP-exact (===) identical to the
        // normalized query, never relying on the WHERE clause's own
        // collation to have already been exact. SQLite's default text
        // comparison is already byte-exact, so this guard is a no-op there
        // but is the load-bearing check on MySQL.
        return $trader->username === $username->value ? $trader : null;
    }
}
