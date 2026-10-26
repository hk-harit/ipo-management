<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProfitPayoutResource\Pages;
use App\Models\Applicant;
use App\Models\ProfitPayout;
use App\Models\Source;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProfitPayoutResource extends Resource
{
    protected static ?string $model = ProfitPayout::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'Profit Payouts';

    protected static ?string $modelLabel = 'Profit Payout';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->description('Profit share (or commission) you paid out. The money leaves the account you pick, and it is deducted from what is still pending for that person.')
                ->schema([
                    Select::make('payee_type')
                        ->label('Paying (કોને ચૂકવ્યા)')
                        ->options(['source' => 'Source (profit share)', 'applicant' => 'Applicant (commission)'])
                        ->default(fn () => request()->query('applicant_id') ? 'applicant' : 'source')
                        ->afterStateHydrated(function (Select $component, ?ProfitPayout $record) {
                            if ($record) {
                                $component->state($record->payee_type);
                            }
                        })
                        ->dehydrated(false)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('source_id')
                        ->label('Source')
                        ->relationship('source', 'name')
                        ->default(fn () => request()->query('source_id'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => $get('payee_type') === 'source')
                        ->required(fn (Get $get) => $get('payee_type') === 'source'),
                    Select::make('applicant_id')
                        ->label('Applicant')
                        ->relationship('applicant', 'name')
                        ->default(fn () => request()->query('applicant_id'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => $get('payee_type') === 'applicant')
                        ->required(fn (Get $get) => $get('payee_type') === 'applicant'),
                    Select::make('account_id')
                        ->label('Paid From Account (કયા ખાતામાંથી)')
                        ->relationship('account', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),
                    TextInput::make('amount')
                        ->minValue(0.01)
                        ->numeric()
                        ->prefix('₹')
                        ->required()
                        ->helperText(fn (Get $get) => static::pendingHint($get)),
                    DatePicker::make('payout_date')
                        ->required()
                        ->default(now()),
                    Textarea::make('notes')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    /** "Pending ₹X" for whoever is picked, so the amount can be checked as it is typed. */
    private static function pendingHint(Get $get): ?string
    {
        if ($get('payee_type') === 'applicant' && filled($get('applicant_id'))) {
            $pending = Applicant::find($get('applicant_id'))?->commission_pending;
        } elseif ($get('payee_type') === 'source' && filled($get('source_id'))) {
            $pending = Source::find($get('source_id'))?->profit_share_pending;
        } else {
            return null;
        }

        return $pending === null ? null : 'Pending to pay: ₹'.number_format($pending, 2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['source', 'applicant', 'account']))
            ->columns([
                TextColumn::make('payout_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('payee_name')
                    ->label('Paid To')
                    ->weight('bold')
                    ->description(fn (ProfitPayout $record) => $record->payee_type === 'source' ? 'Source — profit share' : 'Applicant — commission'),
                TextColumn::make('account.name')->label('From Account')->sortable(),
                TextColumn::make('amount')->money('INR')->weight('bold')->sortable(),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('payout_date', 'desc')
            ->filters([
                SelectFilter::make('payee_type')
                    ->label('Paid To')
                    ->options(['source' => 'Sources', 'applicant' => 'Applicants'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'source' => $query->whereNotNull('source_id'),
                        'applicant' => $query->whereNotNull('applicant_id'),
                        default => $query,
                    }),
                SelectFilter::make('source_id')->label('Source')->relationship('source', 'name'),
                SelectFilter::make('account_id')->label('Account')->relationship('account', 'name'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfitPayouts::route('/'),
            'create' => Pages\CreateProfitPayout::route('/create'),
            'edit' => Pages\EditProfitPayout::route('/{record}/edit'),
        ];
    }
}
