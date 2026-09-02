<?php

namespace App\Filament\Resources\IpoApplicationResource\Pages;

use App\Filament\Resources\IpoApplicationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIpoApplications extends ListRecords
{
    protected static string $resource = IpoApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
