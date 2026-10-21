<?php

namespace App\Filament\Resources\IpoApplicationResource\Pages;

use App\Filament\Resources\IpoApplicationResource;
use App\Models\IpoApplication;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListIpoApplications extends ListRecords
{
    protected static string $resource = IpoApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Applications')
                ->badge(IpoApplication::count()),
            'applied' => Tab::make('Applied (Pending)')
                ->badge(IpoApplication::where('status', 'applied')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'applied')),
            'allotted' => Tab::make('Allotted')
                ->badge(IpoApplication::whereIn('status', ['allotted', 'partial_allotted'])->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['allotted', 'partial_allotted'])),
            'refunded' => Tab::make('Refunded')
                ->badge(IpoApplication::where('status', 'refunded')->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'refunded')),
        ];
    }
}
