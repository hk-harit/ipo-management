<?php

namespace App\Filament\Resources\IpoResource\Pages;

use App\Filament\Resources\IpoResource;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditIpo extends EditRecord
{
    protected static string $resource = IpoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('checkAllotment')
                ->label('Check Allotment')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(fn () => "Check Allotment: {$this->record->name}")
                ->modalDescription('Looks up allotment for all pending applications of this IPO directly with the registrar and updates their status.')
                ->visible(fn () => $this->record->applications()->exists())
                ->action(function (AllotmentCheckService $service) {
                    if (! $this->record->canAutoCheckAllotment()) {
                        Notification::make()
                            ->title('Registrar Not Configured')
                            ->body('Please set Registrar and Registrar Client ID in the "Auto Allotment Check" section below.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $pending = $this->record->applications()->where('status', 'applied')->get();

                    if ($pending->isEmpty()) {
                        Notification::make()
                            ->title('No Pending Applications')
                            ->body('All applications for this IPO are already checked or processed.')
                            ->info()
                            ->send();
                        return;
                    }

                    $counts = $service->checkManyDirect($pending);

                    $summary = "Checked {$counts['total']} application(s): "
                        ."{$counts['allotted']} Allotted · "
                        ."{$counts['refunded']} Refunded";

                    if ($counts['not_found'] > 0) {
                        $summary .= " · {$counts['not_found']} Not Found";
                    }
                    if ($counts['skipped'] > 0) {
                        $summary .= " · {$counts['skipped']} Skipped";
                    }
                    if ($counts['error'] > 0) {
                        $summary .= " · {$counts['error']} Errors";
                    }

                    Notification::make()
                        ->title('Allotment Check Completed')
                        ->body($summary)
                        ->color($counts['allotted'] > 0 ? 'success' : ($counts['error'] > 0 ? 'warning' : 'info'))
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
