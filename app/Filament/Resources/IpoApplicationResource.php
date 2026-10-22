<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IpoApplicationResource\Pages;
use App\Filament\Support\AllotmentCheckBulkAction;
use App\Filament\Support\IpoApplicationFormFields;
use App\Filament\Support\IpoPicker;
use App\Models\IpoApplication;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
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
                    ->label('Payment Account (કયા ખાતામાંથી બ્લોક થાય)')
                    ->helperText('જે ખાતામાંથી UPI/પૈસા બ્લોક થાય તે પસંદ કરો (તમારું ખાતું અથવા દોસ્તનું ખાતું)')
                    ->relationship('account', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('source_id')
                    ->label('Funded By Source')
                    ->helperText('Optional — set this so profit on sale can be split with the right source')
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
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applied_date')->date()->sortable(),
                TextColumn::make('ipo.name')->label('IPO')->searchable()->sortable(),
                TextColumn::make('applicant.name')->label('Applicant')->searchable()->sortable(),
                TextColumn::make('account.name')->label('Account')->searchable()->sortable(),
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
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AllotmentCheckBulkAction::make(),
                    \App\Filament\Support\RefundIpoApplicationAction::bulkMake(),
                    DeleteBulkAction::make(),
                ]),
            ]);
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
