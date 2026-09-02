<?php

namespace App\Filament\Resources\AccountTransferResource\Pages;

use App\Filament\Resources\AccountTransferResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccountTransfer extends EditRecord
{
    protected static string $resource = AccountTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
