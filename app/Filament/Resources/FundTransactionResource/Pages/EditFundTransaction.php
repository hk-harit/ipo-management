<?php

namespace App\Filament\Resources\FundTransactionResource\Pages;

use App\Filament\Resources\FundTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFundTransaction extends EditRecord
{
    protected static string $resource = FundTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
