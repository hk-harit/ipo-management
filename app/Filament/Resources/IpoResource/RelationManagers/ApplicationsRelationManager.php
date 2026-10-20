<?php

namespace App\Filament\Resources\IpoResource\RelationManagers;

use App\Filament\Support\AllotmentCheckBulkAction;
use App\Filament\Support\IpoApplicationFormFields;
use App\Models\IpoApplication;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Applications';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('applicant_id')
                ->label('Applicant')
                ->relationship('applicant', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('account_id')
                ->label('Funded From Account')
                ->relationship('account', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('source_id')
                ->label('Funded By Source')
                ->helperText('Optional — so profit on sale can be split with the right source')
                ->relationship('source', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('amount')
                ->numeric()
                ->prefix('₹')
                ->required(),
            DatePicker::make('applied_date')
                ->required()
                ->default(now()),
            ...IpoApplicationFormFields::statusAndSaleSchema(),
            Textarea::make('notes')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('applied_date')->date()->sortable(),
                TextColumn::make('applicant.name')->label('Applicant')->sortable(),
                TextColumn::make('account.name')->label('Account')->sortable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'warning',
                        'refunded' => 'gray',
                        'allotted', 'partial_allotted' => 'success',
                        'sold' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('return_amount')->money('INR')->label('Returned'),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->state(fn ($record) => $record->profit)
                    ->money('INR')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger'))
                    ->placeholder('—'),
            ])
            ->defaultSort('applied_date', 'desc')
            ->headerActions([
                CreateAction::make(),
                Action::make('checkAllPendingAllotments')
                    ->label('Check All Allotments')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Check Allotment for All Pending Applications')
                    ->modalDescription('Directly looks up allotment results with the registrar for all pending applications and updates their status immediately.')
                    ->action(function (RelationManager $livewire, AllotmentCheckService $service) {
                        $ipo = $livewire->getOwnerRecord();
                        if (! $ipo->canAutoCheckAllotment()) {
                            Notification::make()
                                ->title('Registrar Not Configured')
                                ->body('Please set Registrar and Registrar Client ID in the IPO edit form first.')
                                ->warning()
                                ->send();
                            return;
                        }

                        $pending = $ipo->applications()->where('status', 'applied')->get();

                        if ($pending->isEmpty()) {
                            Notification::make()
                                ->title('No Pending Applications')
                                ->body('All applications for this IPO have already been checked or updated.')
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
            ])
            ->actions([
                Action::make('checkSingleAllotment')
                    ->label('Check')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->tooltip('Check allotment for this applicant directly with registrar')
                    ->visible(fn (IpoApplication $record) => $record->ipo?->canAutoCheckAllotment() && $record->status === 'applied')
                    ->action(function (IpoApplication $record, AllotmentCheckService $service) {
                        $outcome = $service->checkForApplication($record);

                        if ($outcome->status === 'updated') {
                            $record->refresh();
                            $isAllotted = in_array($record->status, ['allotted', 'partial_allotted'], true);

                            Notification::make()
                                ->title($isAllotted ? 'Shares Allotted!' : 'Application Refunded')
                                ->body($isAllotted 
                                    ? "Allotted {$record->shares_allotted} shares for {$record->applicant?->name}!"
                                    : "Not allotted. Marked as refunded.")
                                ->color($isAllotted ? 'success' : 'gray')
                                ->send();
                        } elseif ($outcome->status === 'not_found') {
                            Notification::make()
                                ->title('Not Found on Registrar')
                                ->body('No allotment record found for this PAN yet on registrar website.')
                                ->warning()
                                ->send();
                        } elseif ($outcome->status === 'skipped') {
                            Notification::make()
                                ->title('Skipped')
                                ->body($outcome->message ?? 'Skipped allotment check.')
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Registrar Error')
                                ->body($outcome->message ?? 'Error checking allotment.')
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AllotmentCheckBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
