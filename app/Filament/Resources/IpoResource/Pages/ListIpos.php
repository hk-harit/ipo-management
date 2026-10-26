<?php

namespace App\Filament\Resources\IpoResource\Pages;

use App\Filament\Resources\IpoResource;
use App\Models\Ipo;
use App\Services\IpoFeeds\FeedException;
use App\Services\IpoFeeds\IpoAlertsFeed;
use App\Services\IpoFeeds\IpoSync;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListIpos extends ListRecords
{
    protected static string $resource = IpoResource::class;

    public string $viewMode = 'box';

    public function mount(): void
    {
        parent::mount();
        $this->viewMode = session('ipo_view_mode', 'box');
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['box', 'table'], true) ? $mode : 'box';
        session(['ipo_view_mode' => $this->viewMode]);
    }

    /**
     * Mainboard and SME are really two different games — different lot sizes,
     * different money, different odds — so the first thing to do with the list
     * is pick one. "Live" opens on just the issues still worth acting on.
     */
    public function getTabs(): array
    {
        return [
            'live' => Tab::make('Live')
                ->icon('heroicon-m-bolt')
                ->badge(Ipo::query()->live()->count())
                ->modifyQueryUsing(fn ($query) => $query->live()),

            'closing_today' => Tab::make('Closing Today')
                ->icon('heroicon-m-clock')
                ->badge(Ipo::query()->closingToday()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->closingToday()),

            'opening_today' => Tab::make('Opening Today')
                ->icon('heroicon-m-arrow-right-circle')
                ->badge(Ipo::query()->openingToday()->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn ($query) => $query->openingToday()),

            'closing_tomorrow' => Tab::make('Closing Tomorrow')
                ->icon('heroicon-m-exclamation-circle')
                ->badge(Ipo::query()->closingTomorrow()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->closingTomorrow()),

            'opening_tomorrow' => Tab::make('Opening Tomorrow')
                ->icon('heroicon-m-calendar-days')
                ->badge(Ipo::query()->openingTomorrow()->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn ($query) => $query->openingTomorrow()),

            'application_wise' => Tab::make('Application Wise')
                ->icon('heroicon-m-user-group')
                ->badge(Ipo::query()->withApplications()->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn ($query) => $query->withApplications()->orderByDesc('subscription_applications')),

            'mainboard' => Tab::make('Mainboard')
                ->badge(Ipo::query()->live()->mainboard()->count())
                ->modifyQueryUsing(fn ($query) => $query->live()->mainboard()),

            'sme' => Tab::make('SME')
                ->badge(Ipo::query()->live()->sme()->count())
                ->modifyQueryUsing(fn ($query) => $query->live()->sme()),

            'all' => Tab::make('All')
                ->badge(Ipo::query()->count()),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'live';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleView')
                ->label(fn () => $this->viewMode === 'box' ? 'Table View' : 'Box View')
                ->icon(fn () => $this->viewMode === 'box' ? 'heroicon-m-table-cells' : 'heroicon-m-squares-2x2')
                ->color('gray')
                ->tooltip(fn () => $this->viewMode === 'box' ? 'Switch to Table View' : 'Switch to Box View')
                ->action(fn () => $this->setViewMode($this->viewMode === 'box' ? 'table' : 'box')),
            Actions\Action::make('sync')
                ->label('Sync IPO list')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->tooltip('Pull the latest listing from ipoalerts.in and chittorgarh.com')
                ->action(fn () => $this->sync()),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Pulls the live listing into this user's IPOs. Anything the user has
     * already recorded against an IPO survives — see IpoSync.
     */
    private function sync(): void
    {
        $sync = IpoSync::fromConfig();

        try {
            $result = $sync->syncFor(auth()->user());
        } catch (FeedException $exception) {
            Notification::make()
                ->title('Could not sync IPOs')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title($result->touchedAnything() ? 'IPOs synced' : 'Already up to date')
            ->body($result->summary().$this->caveats($sync))
            ->success()
            ->send();
    }

    /** Anything the user should know about how complete this run was. */
    private function caveats(IpoSync $sync): string
    {
        $notes = [];

        foreach ($sync->failures() as $label => $reason) {
            $notes[] = $label.' was skipped: '.$reason;
        }

        if (! IpoAlertsFeed::fromConfig()->hasApiKey()) {
            $notes[] = 'Add IPOALERTS_API_KEY to .env for the full ipoalerts.in listing.';
        }

        return $notes === [] ? '' : ' '.implode(' ', $notes);
    }
}
