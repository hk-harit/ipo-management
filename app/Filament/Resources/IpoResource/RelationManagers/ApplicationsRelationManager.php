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
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                ->label('Applicant (કોના ડીમેટ/નામે)')
                ->helperText('Demat account holder')
                ->relationship('applicant', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('account_id')
                ->label('Payment Account (મુખ્ય ખાતું)')
                ->helperText('જે ખાતામાંથી UPI mandate અથવા પૈસા બ્લોક થાય')
                ->relationship('account', 'name', fn ($query) => $query->where('is_sub_account', false))
                ->required()
                ->searchable()
                ->preload()
                ->live(),
            Select::make('sub_account_id')
                ->label('Sub-Account (સબ એકાઉન્ટ - ઓપ્શનલ)')
                ->helperText('જો ડાયરેક્ટ ખાતામાંથી ન ભરવું હોય તો સબ એકાઉન્ટ પસંદ કરો, ફંડ તેમાં બોલશે')
                ->options(function (\Filament\Forms\Get $get) {
                    $accId = $get('account_id');
                    if (! $accId) {
                        return \App\Models\Account::where('is_sub_account', true)->pluck('name', 'id');
                    }
                    return \App\Models\Account::where('is_sub_account', true)
                        ->where('parent_id', $accId)
                        ->pluck('name', 'id');
                })
                ->placeholder('Direct (મુખ્ય ખાતામાંથી સીધા)')
                ->searchable()
                ->preload()
                ->live(),
            Select::make('funding_type')
                ->label('Payment Method (પૈસા કેવી રીતે કપાયા)')
                ->options([
                    'direct' => 'Direct (આ જ ખાતામાંથી સીધા પૈસા બ્લોક)',
                    'transferred' => 'Transferred from another account (બીજા ખાતામાંથી નાખીને)',
                ])
                ->default('direct')
                ->required()
                ->live()
                ->native(false),
            Select::make('funding_account_id')
                ->label('Transferred From Account (કયા ખાતામાંથી મોકલ્યા)')
                ->helperText('પસંદ કરશો એટલે ઓટોમેટિક ખાતા ટ્રાન્સફર (Account Transfer) પણ થઈ જશે')
                ->relationship('fundingAccount', 'name')
                ->searchable()
                ->preload()
                ->visible(fn (\Filament\Forms\Get $get) => $get('funding_type') === 'transferred')
                ->required(fn (\Filament\Forms\Get $get) => $get('funding_type') === 'transferred'),
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
                TextColumn::make('account.name')
                    ->label('Account')
                    ->sortable()
                    ->description(function (IpoApplication $record) {
                        $parts = [];
                        if ($record->subAccount) {
                            $parts[] = "Sub: {$record->subAccount->name}";
                        }
                        if ($record->funding_type === 'transferred' && $record->fundingAccount) {
                            $parts[] = "via {$record->fundingAccount->name} (Transfer)";
                        }
                        return ! empty($parts) ? implode(' · ', $parts) : 'Direct';
                    }),
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
                    ->modalHeading(fn (RelationManager $livewire) => "Check Allotment: " . ($livewire->getOwnerRecord()->name ?? 'All Applications'))
                    ->modalDescription(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->canAutoCheckAllotment()
                        ? 'Directly looks up allotment results with the registrar for all pending applications and updates their status immediately.'
                        : 'Registrar is not configured for this IPO yet. Please select the Registrar and Company below to save and check immediately:'
                    )
                    ->form(fn (RelationManager $livewire, AllotmentCheckService $service) => ! $livewire->getOwnerRecord()->canAutoCheckAllotment() ? [
                        Select::make('registrar')
                            ->label('Registrar')
                            ->options(AllotmentCheckService::registrarOptions())
                            ->required()
                            ->live()
                            ->native(false)
                            ->afterStateUpdated(function (Set $set, ?string $state) use ($service, $livewire) {
                                if ($state) {
                                    $matchedId = $service->matchCompanyId($state, $livewire->getOwnerRecord()->name);
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
                            ->default(fn (Get $get) => $get('registrar') ? $service->matchCompanyId($get('registrar'), $livewire->getOwnerRecord()->name) : null)
                            ->helperText('Select this company from the registrar\'s live list.'),
                    ] : [])
                    ->action(function (RelationManager $livewire, array $data, AllotmentCheckService $service) {
                        $ipo = $livewire->getOwnerRecord();
                        if (! $ipo->canAutoCheckAllotment()) {
                            if (! empty($data['registrar']) && ! empty($data['registrar_client_id'])) {
                                $ipo->update([
                                    'registrar' => $data['registrar'],
                                    'registrar_client_id' => $data['registrar_client_id'],
                                ]);
                            } else {
                                Notification::make()
                                    ->title('Registrar Not Configured')
                                    ->body('Please select Registrar and Company first.')
                                    ->warning()
                                    ->send();
                                return;
                            }
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
            ])
            ->actions([
                Action::make('checkSingleAllotment')
                    ->label('Check')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->tooltip('Check allotment for this applicant directly with registrar')
                    ->visible(fn (IpoApplication $record, RelationManager $livewire) => ($record->ipo ?? $livewire->getOwnerRecord())?->canAutoCheckAllotment() && $record->status === 'applied')
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
                \App\Filament\Support\RefundIpoApplicationAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AllotmentCheckBulkAction::make(),
                    \App\Filament\Support\RefundIpoApplicationAction::bulkMake(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
