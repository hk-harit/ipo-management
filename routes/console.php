<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Keep the IPO list current on its own, every 5 minutes — GMP and subscription
 * figures move through the day while an issue is open, so this keeps them fresh.
 * withoutOverlapping() means a slow run just gets skipped rather than stacking;
 * watch IPOALERTS_MAX_PAGES / the ipoalerts.in free-tier quota if this starts
 * tripping rate limits — chittorgarh.com needs no key and isn't affected.
 *
 * Needs the scheduler running: `php artisan schedule:work` (or a cron/Task
 * Scheduler entry calling `schedule:run` every minute). The "Sync from
 * ipoalerts.in" button in the panel works regardless.
 */
Schedule::command('ipos:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
