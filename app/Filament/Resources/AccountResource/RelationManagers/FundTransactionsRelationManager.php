<?php

namespace App\Filament\Resources\AccountResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'fundTransactions';

    protected static ?string $title = 'Fund Transactions';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('source_id')
                ->label('Source')
                ->relationship('source', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('type')
                ->options([
                    'given' => 'Given (source → this account)',
                    'returned' => 'Returned (this account → source)',
                ])
                ->required()
                ->native(false),
            TextInput::make('amount')
                ->minValue(0.01)
                ->numeric()
                ->prefix('₹')
                ->required(),
            DatePicker::make('transaction_date')
                ->required()
                ->default(now()),
            Textarea::make('notes')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('transaction_date')->date()->sortable(),
                TextColumn::make('source.name')->label('Source')->sortable(),
                TextColumn::make('type')->badge()
                    ->color(fn (string $state) => $state === 'given' ? 'success' : 'warning'),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('notes')->limit(30),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
