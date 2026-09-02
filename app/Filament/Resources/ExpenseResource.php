<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
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

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                Select::make('account_id')
                    ->label('Paid From Account')
                    ->relationship('account', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('category')
                    ->placeholder('e.g. Brokerage, Food, Travel'),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('₹')
                    ->required(),
                DatePicker::make('expense_date')
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
                TextColumn::make('expense_date')->date()->sortable(),
                TextColumn::make('account.name')->label('Account')->searchable()->sortable(),
                TextColumn::make('category')->searchable()->badge()->color('gray'),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([
                SelectFilter::make('account_id')->label('Account')->relationship('account', 'name'),
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
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
