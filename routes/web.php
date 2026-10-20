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

/**
 * Webhook/URL Cron Runner for Hostinger to check pending allotments.
 * Usage: https://yourdomain.com/cron/check-allotments?token=YOUR_CRON_TOKEN
 */
Route::get('/cron/check-allotments', function (Request $request, \App\Services\AllotmentCheck\AllotmentCheckService $service) {
    $expectedToken = env('CRON_TOKEN', 'ipo_live_sync_2026');
    $providedToken = $request->query('token');

    if (! $providedToken || $providedToken !== $expectedToken) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized: Invalid or missing token.',
        ], 403);
    }

    $pending = \App\Models\IpoApplication::where('status', 'applied')
        ->whereHas('ipo', fn ($q) => $q->whereNotNull('registrar')->whereNotNull('registrar_client_id'))
        ->get();

    $counts = $service->checkManyDirect($pending);

    return response()->json([
        'status' => 'success',
        'message' => 'Allotment check completed.',
        'checked' => $counts,
        'timestamp' => now()->toIso8601String(),
    ]);
});
