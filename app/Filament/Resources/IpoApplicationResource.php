<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IpoApplicationResource\Pages;
use App\Filament\Support\AllotmentCheckBulkAction;
use App\Filament\Support\IpoApplicationFormFields;
use App\Filament\Support\IpoPicker;
use App\Filament\Support\RefundIpoApplicationAction;
use App\Models\Account;
use App\Models\IpoApplication;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IpoApplicationResource extends Resource
{
    protected static ?string $model = IpoApplication::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'All Applications';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                IpoPicker::select(),
                IpoPicker::summary(),
                Select::make('applicant_id')
                    ->label('Applicant (કોના ડીમેટ/નામે)')
                    ->helperText('Demat account holder whose name the IPO is applied in')
                    ->relationship('applicant', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('account_id')
                    ->label('Payment Account (મુખ્ય ખાતું)')
                    ->helperText('જે ખાતામાંથી UPI mandate અથવા પૈસા બ્લોક થાય')
                    ->relationship('account', 'name', fn ($query) => Account::hasSubAccountColumn() ? $query->where('is_sub_account', false) : $query)
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live(),
                Select::make('sub_account_id')
                    ->label('Sub-Account (સબ એકાઉન્ટ - ઓપ્શનલ)')
                    ->helperText('જો ડાયરેક્ટ ખાતામાંથી ન ભરવું હોય તો સબ એકાઉન્ટ પસંદ કરો, ફંડ તેમાં બોલશે')
                    ->options(function (Get $get) {
                        if (! Account::hasSubAccountColumn()) {
                            return [];
                        }
                        $accId = $get('account_id');
                        if (! $accId) {
                            return Account::where('is_sub_account', true)->pluck('name', 'id');
                        }

                        return Account::where('is_sub_account', true)
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
                    ->visible(fn (Get $get) => $get('funding_type') === 'transferred')
                    ->required(fn (Get $get) => $get('funding_type') === 'transferred'),
                Select::make('source_id')
                    ->label('Funded By Source')
                    ->helperText('Optional — set this so profit on sale can be split with the right source')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('amount')
                    ->minValue(0.01)
                    ->numeric()
                    ->prefix('₹')
                    ->required(),
                DatePicker::make('applied_date')
                    ->required()
                    ->default(now()),
                ...IpoApplicationFormFields::statusAndSaleSchema(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns([
                'default' => 1,
                'md' => 2,
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applied_date')->date()->sortable(),
                TextColumn::make('ipo.name')->label('IPO')->searchable()->sortable(),
                TextColumn::make('applicant.name')->label('Applicant')->searchable()->sortable(),
                TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
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
                TextColumn::make('source.name')->label('Source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'warning',
                        'refunded' => 'gray',
                        'allotted', 'partial_allotted' => 'success',
                        'sold' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('return_amount')->money('INR')->label('Returned')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('refund_shortfall')
                    ->label('Short Refund')
                    ->state(fn (IpoApplication $record) => $record->refund_shortfall)
                    ->money('INR')
                    ->color('danger')
                    ->placeholder('—')
                    ->tooltip('Applied amount minus what the refund actually returned')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->state(fn (IpoApplication $record) => $record->profit)
                    ->money('INR')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger'))
                    ->placeholder('—'),
            ])
            ->defaultSort('applied_date', 'desc')
            ->groups([
                Group::make('ipo.name')
                    ->label('IPO')
                    ->collapsible(),
            ])
            ->defaultGroup('ipo.name')
            ->filters([
                SelectFilter::make('ipo_id')
                    ->label('Select IPO (IPO પસંદ કરો)')
                    ->relationship('ipo', 'name', fn (Builder $query) => $query->whereHas('applications')->orderByDesc('id'))
                    ->searchable()
                    ->preload()
                    ->placeholder('All IPOs (બધા જ IPO)'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'applied' => 'Applied',
                        'refunded' => 'Refunded',
                        'allotted' => 'Allotted',
                        'partial_allotted' => 'Partially Allotted',
                        'sold' => 'Sold',
                    ])
                    ->placeholder('All Status'),
                SelectFilter::make('applicant_id')
                    ->label('Applicant')
                    ->relationship('applicant', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All Applicants'),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All Accounts'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'sm' => 1,
                'md' => 2,
                'lg' => 4,
            ])
            ->actions([
                Action::make('checkAllotment')
                    ->label('Check')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->tooltip('Check allotment status with registrar')
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
                                    : 'Not allotted. Marked as refunded.')
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
                RefundIpoApplicationAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AllotmentCheckBulkAction::make(),
                    RefundIpoApplicationAction::bulkMake(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\IpoApplicationResource\RelationManagers\SalesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpoApplications::route('/'),
            'create' => Pages\CreateIpoApplication::route('/create'),
            'edit' => Pages\EditIpoApplication::route('/{record}/edit'),
        ];
    }
}
