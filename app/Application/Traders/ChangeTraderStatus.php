<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Models\Trader;
use App\Models\TraderStatus;

/**
 * Single business entry point for changing a Trader's triage status among
 * Candidate, Watched, and Ignored. No HTTP, no ImportRun bookkeeping, no
 * Filament/Livewire dependency. A same-status call is an idempotent no-op
 * in outcome — the row is saved and refreshed either way, but no field
 * actually changes.
 */
final class ChangeTraderStatus
{
    public function handle(Trader $trader, TraderStatus $status): Trader
    {
        $trader->forceFill(['status' => $status])->save();

        return $trader->refresh();
    }
}
