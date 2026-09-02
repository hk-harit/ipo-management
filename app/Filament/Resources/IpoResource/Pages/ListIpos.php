<?php

namespace App\Filament\Resources\IpoResource\Pages;

use App\Filament\Resources\IpoResource;
use App\Services\IpoFeeds\FeedException;
use App\Services\IpoFeeds\IpoAlertsFeed;
use App\Services\IpoFeeds\IpoSync;
use App\Models\Ipo;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListIpos extends ListRecords
{
    protected static string $resource = IpoResource::class;

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
