<?php

namespace App\Filament\Resources\AccountTransferResource\Pages;

use App\Filament\Resources\AccountTransferResource;
use App\Filament\Widgets\AccountSettlementHisabWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAccountTransfers extends ListRecords
{
    protected static string $resource = AccountTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccountSettlementHisabWidget::class,
        ];
    }
}
