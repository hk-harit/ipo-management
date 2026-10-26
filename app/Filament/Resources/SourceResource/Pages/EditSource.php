<?php

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use App\Filament\Support\GuardedDelete;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('statement')
                ->label('Statement')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => SourceResource::getUrl('statement', ['record' => $this->record])),
            GuardedDelete::page(),
        ];
    }
}
