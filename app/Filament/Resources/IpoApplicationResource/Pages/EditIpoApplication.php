<?php

namespace App\Filament\Resources\IpoApplicationResource\Pages;

use App\Filament\Resources\IpoApplicationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIpoApplication extends EditRecord
{
    protected static string $resource = IpoApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
