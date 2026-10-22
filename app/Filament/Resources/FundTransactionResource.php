<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FundTransactionResource\Pages;
use App\Models\FundTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FundTransactionResource extends Resource
{
    protected static ?string $model = FundTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'All Transactions';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                Select::make('source_id')
                    ->label('Source')
                    ->relationship('source', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('account_id')
                    ->label('Account')
                    ->relationship('account', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('type')
                    ->options([
                        'given' => 'Given (source → account)',
                        'returned' => 'Returned (account → source)',
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('₹')
                    ->required(),
                DatePicker::make('transaction_date')
                    ->required()
                    ->default(now()),
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
                TextColumn::make('transaction_date')->date()->sortable(),
                TextColumn::make('source.name')->label('Source')->searchable()->sortable(),
                TextColumn::make('account.name')->label('Account')->searchable()->sortable(),
                TextColumn::make('type')->badge()
                    ->color(fn (string $state) => $state === 'given' ? 'success' : 'warning'),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->filters([
                SelectFilter::make('source_id')->label('Source')->relationship('source', 'name'),
                SelectFilter::make('account_id')->label('Account')->relationship('account', 'name'),
                SelectFilter::make('type')->options([
                    'given' => 'Given',
                    'returned' => 'Returned',
                ]),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFundTransactions::route('/'),
            'create' => Pages\CreateFundTransaction::route('/create'),
            'edit' => Pages\EditFundTransaction::route('/{record}/edit'),
        ];
    }
}
