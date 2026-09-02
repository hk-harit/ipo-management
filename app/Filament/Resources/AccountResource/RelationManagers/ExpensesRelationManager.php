<?php

namespace App\Filament\Resources\AccountResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Expenses';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('category')
                ->placeholder('e.g. Brokerage, Food, Travel')
                ->maxLength(255),
            TextInput::make('amount')
                ->numeric()
                ->prefix('₹')
                ->required(),
            DatePicker::make('expense_date')
                ->required()
                ->default(now()),
            Textarea::make('notes')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('category')
            ->columns([
                TextColumn::make('expense_date')->date()->sortable(),
                TextColumn::make('category')->searchable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('notes')->limit(30),
            ])
            ->defaultSort('expense_date', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
