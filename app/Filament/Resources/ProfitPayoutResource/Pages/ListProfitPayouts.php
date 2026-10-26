<?php

namespace App\Filament\Resources\ProfitPayoutResource\Pages;

use App\Filament\Resources\ProfitPayoutResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProfitPayouts extends ListRecords
{
    protected static string $resource = ProfitPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
