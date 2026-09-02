<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountTransferResource\Pages;
use App\Models\AccountTransfer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountTransferResource extends Resource
{
    protected static ?string $model = AccountTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'Transfers Between Accounts';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->description('Move money between two of your own tracked accounts — e.g. you fund an application from a friend\'s account, but their bank has an issue, so you send it there from your own bank first.')
                ->schema([
                    Select::make('from_account_id')
                        ->label('From Account')
                        ->relationship('fromAccount', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('to_account_id')
                        ->label('To Account')
                        ->relationship('toAccount', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->rules([
                            fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if ($value && $value == $get('from_account_id')) {
                                    $fail('From and To account must be different.');
                                }
                            },
                        ]),
                    TextInput::make('amount')
                        ->numeric()
                        ->prefix('₹')
                        ->required(),
                    DatePicker::make('transfer_date')
                        ->required()
                        ->default(now()),
                    Textarea::make('notes')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transfer_date')->date()->sortable(),
                TextColumn::make('fromAccount.name')->label('From')->searchable()->sortable(),
                TextColumn::make('toAccount.name')->label('To')->searchable()->sortable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('transfer_date', 'desc')
            ->filters([
                SelectFilter::make('from_account_id')->label('From Account')->relationship('fromAccount', 'name'),
                SelectFilter::make('to_account_id')->label('To Account')->relationship('toAccount', 'name'),
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
            'index' => Pages\ListAccountTransfers::route('/'),
            'create' => Pages\CreateAccountTransfer::route('/create'),
            'edit' => Pages\EditAccountTransfer::route('/{record}/edit'),
        ];
    }
}
