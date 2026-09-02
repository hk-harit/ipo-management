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
        //
    }
}
