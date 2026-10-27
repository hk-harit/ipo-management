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

class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Expenses & Loans';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('type')
                ->label('Type')
                ->options([
                    'expense' => 'Regular Expense',
                    'udhar_given' => 'Loan Given',
                    'udhar_received' => 'Loan Received',
                ])
                ->default('expense')
                ->required()
                ->live(),

            TextInput::make('person_name')
                ->label('Person Name')
                ->placeholder('e.g. Ramesh, Rahul')
                ->visible(fn ($get) => in_array($get('type'), ['udhar_given', 'udhar_received']))
                ->required(fn ($get) => in_array($get('type'), ['udhar_given', 'udhar_received'])),

            Select::make('status')
                ->label('Status')
                ->options([
                    'pending' => 'Pending',
                    'settled' => 'Settled',
                ])
                ->default('pending')
                ->visible(fn ($get) => $get('type') === 'udhar_given'),

            TextInput::make('category')
                ->placeholder('e.g. Brokerage, Food, Travel')
                ->visible(fn ($get) => $get('type') === 'expense')
                ->maxLength(255),

            TextInput::make('amount')
                ->minValue(0.01)
                ->numeric()
                ->prefix('₹')
                ->required(),

            DatePicker::make('expense_date')
                ->label('Date')
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
                TextColumn::make('expense_date')->date('d M Y')->sortable(),
                TextColumn::make('type')->badge()->color(fn ($state) => match ($state) {
                    'expense' => 'gray',
                    'udhar_given' => 'warning',
                    'udhar_received' => 'success',
                    default => 'gray',
                }),
                TextColumn::make('person_name')->label('Person')->placeholder('—'),
                TextColumn::make('category')->searchable()->placeholder('—'),
                TextColumn::make('amount')->money('INR')->sortable()->weight('bold'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'pending' => 'danger',
                    'settled' => 'success',
                    default => 'gray',
                }),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
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
