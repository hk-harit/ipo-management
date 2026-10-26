<?php

use App\Models\IpoApplication;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
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

// Fallback for unexpected GET requests to /livewire/update (prevents 405 MethodNotAllowed errors on browser refresh or redirects)
Route::match(['get', 'head'], '/livewire/update', function () {
    return redirect()->to('/admin');
});

/**
 * Maintenance helpers for hosts without SSH (Hostinger shared hosting).
 * Admin-only: you must be signed in to /admin with an admin login first.
 */
Route::prefix('maintenance')->group(function () {
    $guardAdmin = function () {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
    };

    Route::get('/migrate', function () use ($guardAdmin) {
        $guardAdmin();

        try {
            Artisan::call('migrate', ['--force' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Database migrations executed successfully.',
                'output' => trim(Artisan::output()),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Migration failed. Check storage/logs/laravel.log.',
            ], 500);
        }
    });

    Route::get('/clear-cache', function () use ($guardAdmin) {
        $guardAdmin();

        try {
            Artisan::call('optimize:clear');

            return response()->json([
                'status' => 'success',
                'message' => 'Application cache cleared successfully.',
                'output' => trim(Artisan::output()),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'Cache clear failed. Check storage/logs/laravel.log.',
            ], 500);
        }
    });
});

/**
 * The cron URLs below are for Hostinger or an external cron (e.g. cron-job.org).
 * They need CRON_TOKEN set in .env — with no token configured they stay closed.
 * Pass it as ?token=… or, better, an `X-Cron-Token` header so it stays out of logs.
 */
$cronAuthorised = function (Request $request): bool {
    $expected = (string) config('services.cron.token');
    $provided = (string) ($request->header('X-Cron-Token') ?: $request->query('token'));

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
};

Route::get('/cron/sync-ipos', function (Request $request) use ($cronAuthorised) {
    abort_unless($cronAuthorised($request), 403, 'Unauthorized');

    Artisan::call('ipos:sync');

    return response()->json([
        'status' => 'success',
        'message' => 'IPOs synced successfully.',
        'output' => trim(Artisan::output()),
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/cron/check-allotments', function (Request $request, AllotmentCheckService $service) use ($cronAuthorised) {
    abort_unless($cronAuthorised($request), 403, 'Unauthorized');

    $pending = IpoApplication::where('status', 'applied')
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
