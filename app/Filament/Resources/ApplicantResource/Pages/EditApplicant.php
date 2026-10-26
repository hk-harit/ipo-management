<?php

namespace App\Filament\Resources\ApplicantResource\Pages;

use App\Filament\Resources\ApplicantResource;
use App\Filament\Support\GuardedDelete;
use Filament\Resources\Pages\EditRecord;

class EditApplicant extends EditRecord
{
    protected static string $resource = ApplicantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDelete::page(),
        ];
    }
}
