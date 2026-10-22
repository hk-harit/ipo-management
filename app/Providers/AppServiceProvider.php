<?php

namespace App\Providers;

use App\Services\AllotmentCheck\CaptchaSolver;
use App\Services\AllotmentCheck\TesseractCaptchaSolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CaptchaSolver::class, fn () => new TesseractCaptchaSolver(
            config('services.tesseract.binary', 'tesseract')
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auto-run pending database migrations on web requests if new columns are missing,
        // so live server (Hostinger shared hosting) never crashes or requires manual SSH commands.
        if (! $this->app->runningInConsole()) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('migrations')) {
                    if (! \Illuminate\Support\Facades\Schema::hasColumn('ipo_applications', 'sub_account_id')
                        || ! \Illuminate\Support\Facades\Schema::hasColumn('accounts', 'is_sub_account')
                        || ! \Illuminate\Support\Facades\Schema::hasColumn('ipo_applications', 'funding_type')
                    ) {
                        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
