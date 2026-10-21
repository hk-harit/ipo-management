<?php

namespace App\Filament\Resources\IpoResource\Pages;

use App\Filament\Resources\IpoResource;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                ->modalDescription(fn () => $this->record->canAutoCheckAllotment()
                    ? "Looks up allotment for all pending applications of this IPO directly with the registrar and updates their status."
                    : "Registrar is not configured for this IPO yet. Please select the Registrar and Company below to save and check immediately:"
                )
                ->form(fn (AllotmentCheckService $service) => ! $this->record->canAutoCheckAllotment() ? [
                    Select::make('registrar')
                        ->label('Registrar')
                        ->options(AllotmentCheckService::registrarOptions())
                        ->required()
                        ->live()
                        ->native(false)
                        ->afterStateUpdated(function (Set $set, ?string $state) use ($service) {
                            if ($state) {
                                $matchedId = $service->matchCompanyId($state, $this->record->name);
                                if ($matchedId) {
                                    $set('registrar_client_id', $matchedId);
                                }
                            }
                        }),
                    Select::make('registrar_client_id')
                        ->label('Company on Registrar')
                        ->placeholder('Select Company...')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->options(function (Get $get) use ($service) {
                            $reg = $get('registrar');
                            return $reg ? $service->getRegistrarCompanies($reg) : [];
                        })
                        ->default(fn (Get $get) => $get('registrar') ? $service->matchCompanyId($get('registrar'), $this->record->name) : null)
                        ->helperText('Select this company from the registrar\'s live list.'),
                ] : [])
                ->visible(fn () => $this->record->applications()->exists())
                ->action(function (array $data, AllotmentCheckService $service) {
                    if (! $this->record->canAutoCheckAllotment()) {
                        if (! empty($data['registrar']) && ! empty($data['registrar_client_id'])) {
                            $this->record->update([
                                'registrar' => $data['registrar'],
                                'registrar_client_id' => $data['registrar_client_id'],
                            ]);
                            $this->fillForm();
                        } else {
                            Notification::make()
                                ->title('Registrar Not Configured')
                                ->body('Please select Registrar and Company first.')
                                ->warning()
                                ->send();
                            return;
                        }
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
                        if (! empty($counts['errors'])) {
                            $summary .= ' ('.implode(', ', array_slice($counts['errors'], 0, 2)).')';
                        }
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
