<?php

namespace App\Filament\Resources\IpoResource\Pages;

use App\Filament\Resources\IpoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIpo extends EditRecord
{
    protected static string $resource = IpoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
