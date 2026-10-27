<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Loan given that has not come back yet, soonest due first. */
class PendingUdharWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Loan to Receive';

    public static function canView(): bool
    {
        return Expense::query()->pendingUdhar()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Expense::query()->pendingUdhar()->with('account')
                    ->orderByRaw('due_date IS NULL')
                    ->orderBy('due_date')
                    ->orderBy('expense_date')
            )
            ->columns([
                TextColumn::make('person_name')->label('Person')->weight('bold')->placeholder('—'),
                TextColumn::make('amount')->money('INR')->weight('bold')->color('warning'),
                TextColumn::make('expense_date')->label('Given On')->date('d M Y')->visibleFrom('sm'),
                TextColumn::make('due_date')
                    ->label('Expected Back')
                    ->date('d M Y')
                    ->placeholder('No date')
                    ->badge()
                    ->color(fn (Expense $record) => $record->due_date && $record->due_date->isPast() ? 'danger' : 'gray')
                    ->description(fn (Expense $record) => $record->due_date && $record->due_date->isPast()
                        ? $record->due_date->diffInDays(today()).' day(s) overdue'
                        : null),
                TextColumn::make('account.name')->label('From Account')->visibleFrom('md'),
            ])
            ->actions([
                Action::make('receive')
                    ->label('Receive')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->url(fn () => ExpenseResource::getUrl('index', ['tableFilters' => ['type' => ['value' => 'udhar_given'], 'status' => ['value' => 'pending']]])),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
