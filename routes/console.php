<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PROJECT.md §16: daily 03:00 UTC performance sync for watched traders.
// Only queues jobs; requires `schedule:run` (cron) and a queue worker.
Schedule::command('etoro:sync-performance --watched')
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping();

// docs/DECISIONS.md D-051: daily 03:30 UTC snapshot of the own DEMO account
// (one GET). Daily, not the hourly of PROJECT.md §16: equity history is read
// per day, the account is a demo, and every call counts against the shared
// eToro quota. Real is disabled in code until Demo acceptance.
Schedule::command('etoro:sync-account --demo')
    ->dailyAt('03:30')
    ->timezone('UTC')
    ->withoutOverlapping();
