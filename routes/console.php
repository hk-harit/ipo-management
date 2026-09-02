<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Keep the IPO list current on its own. Twice a day is enough — the feed changes
 * when an issue opens, closes or lists, not by the minute — and it stays well
 * inside the free tier's rate limit.
 *
 * Needs the scheduler running: `php artisan schedule:work` (or a cron/Task
 * Scheduler entry calling `schedule:run` every minute). The "Sync from
 * ipoalerts.in" button in the panel works regardless.
 */
Schedule::command('ipos:sync')
    ->twiceDaily(9, 18)
    ->withoutOverlapping()
    ->runInBackground();
