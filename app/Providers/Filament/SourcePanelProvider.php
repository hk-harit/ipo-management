<?php

namespace App\Providers\Filament;

use App\Filament\Source\Pages\Auth\SourceLogin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The read-only portal a funding Source logs into at /source.
 *
 * Deliberately separate from the admin panel: its own guard, its own login page,
 * a different accent colour so there is no mistaking one for the other, and no
 * resources at all — every page here only ever reads the signed-in source's data.
 */
class SourcePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('source')
            ->path('source')
            ->authGuard('source')
            ->login(SourceLogin::class)
            ->brandName('My IPO Account')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverPages(in: app_path('Filament/Source/Pages'), for: 'App\\Filament\\Source\\Pages')
            ->discoverWidgets(in: app_path('Filament/Source/Widgets'), for: 'App\\Filament\\Source\\Widgets')
            ->pages([
                \App\Filament\Source\Pages\Dashboard::class,
            ])
            ->widgets([
                \App\Filament\Source\Widgets\PortalStats::class,
                \App\Filament\Source\Widgets\IpoWiseSummary::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
