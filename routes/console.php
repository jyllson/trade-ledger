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
