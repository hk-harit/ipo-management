<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Root URL redirects straight to the Filament Admin panel
Route::get('/', function () {
    return redirect('/admin');
});

/**
 * Webhook/URL Cron Runner for Hostinger or External Cron (e.g. cron-job.org).
 * Usage: https://yourdomain.com/cron/sync-ipos?token=YOUR_CRON_TOKEN
 */
Route::get('/cron/sync-ipos', function (Request $request) {
    $expectedToken = env('CRON_TOKEN', 'ipo_live_sync_2026');
    $providedToken = $request->query('token');

    if (! $providedToken || $providedToken !== $expectedToken) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized: Invalid or missing token.',
        ], 403);
    }

    Artisan::call('ipos:sync');
    $output = Artisan::output();

    return response()->json([
        'status' => 'success',
        'message' => 'IPOs synced successfully.',
        'output' => trim($output),
        'timestamp' => now()->toIso8601String(),
    ]);
});
