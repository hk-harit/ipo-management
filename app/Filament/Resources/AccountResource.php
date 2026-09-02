<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Filament\Resources\AccountResource\RelationManagers;
use App\Models\Account;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->placeholder('e.g. My Bank, Mummy\'s Bank, Cash')
                    ->required()
                    ->maxLength(255),
                TextInput::make('holder_name')
                    ->label('Held By')
                    ->placeholder('e.g. Self, Mummy, Papa, Nirbhay')
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'bank' => 'Bank',
                        'cash' => 'Cash',
                    ])
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('bank_name')
                    ->maxLength(255)
                    ->visible(fn ($get) => $get('type') === 'bank'),
                TextInput::make('account_number')
                    ->maxLength(255)
                    ->visible(fn ($get) => $get('type') === 'bank'),
                TextInput::make('opening_balance')
                    ->numeric()
                    ->prefix('₹')
                    ->required()
                    ->default(0),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('holder_name')->label('Held By')->searchable(),
                TextColumn::make('type')->badge()
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'gray'),
                TextColumn::make('opening_balance')->money('INR')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_balance')
                    ->label('Current Balance')
                    ->state(fn (Account $record) => $record->current_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->defaultSort('name')
            ->filters([
                //
            ])
            ->actions([
                Action::make('passbook')
                    ->label('Passbook')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (Account $record) => static::getUrl('passbook', ['record' => $record])),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\FundTransactionsRelationManager::class,
            RelationManagers\IpoApplicationsRelationManager::class,
            RelationManagers\ExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
            'passbook' => Pages\AccountPassbook::route('/{record}/passbook'),
        ];
    }
}
