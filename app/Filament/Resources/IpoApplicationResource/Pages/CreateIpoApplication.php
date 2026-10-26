<?php

namespace App\Filament\Resources\IpoApplicationResource\Pages;

use App\Filament\Resources\IpoApplicationResource;
use App\Filament\Support\ApplicationWarnings;
use Filament\Resources\Pages\CreateRecord;

class CreateIpoApplication extends CreateRecord
{
    protected static string $resource = IpoApplicationResource::class;

    protected function afterCreate(): void
    {
        ApplicationWarnings::notify($this->record);
    }
}
