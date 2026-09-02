<?php

namespace App\Filament\Resources\FundTransactionResource\Pages;

use App\Filament\Resources\FundTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFundTransaction extends CreateRecord
{
    protected static string $resource = FundTransactionResource::class;
}
