<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AccountBalancesTable;
use App\Filament\Widgets\AccountSettlementHisabWidget;
use App\Filament\Widgets\AllotmentOutcomeChart;
use App\Filament\Widgets\AllottedAwaitingSaleWidget;
use App\Filament\Widgets\ApplicantCommissionWidget;
use App\Filament\Widgets\IpoCapitalDistributionChart;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PendingUdharWidget;
use App\Filament\Widgets\ProfitTrendChart;
use App\Filament\Widgets\SourceDuesWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('IPO Manage PRO')
            ->brandLogo(fn () => view('filament.components.brand-logo'))
            ->brandLogoHeight('2.6rem')
            ->font('Outfit')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->collapsedSidebarWidth('4.5rem')
            ->renderHook(
                'panels::head.done',
                fn (): HtmlString => new HtmlString(
                    '<link rel="stylesheet" href="'.asset('css/filament-theme.css').'?v='.filemtime(public_path('css/filament-theme.css')).'">'
                )
            )
            ->renderHook('panels::head.end', fn () => view('pwa.head', ['manifest' => 'manifest.webmanifest']))
            ->renderHook('panels::body.end', fn () => view('pwa.register'))
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                OverviewStats::class,
                AccountBalancesTable::class,
                ProfitTrendChart::class,
                IpoCapitalDistributionChart::class,
                AllotmentOutcomeChart::class,
                AllottedAwaitingSaleWidget::class,
                NeedsAttentionWidget::class,
                SourceDuesWidget::class,
                ApplicantCommissionWidget::class,
                PendingUdharWidget::class,
                AccountSettlementHisabWidget::class,
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
