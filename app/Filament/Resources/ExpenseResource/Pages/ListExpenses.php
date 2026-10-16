<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Filament\Resources\ExpenseResource\Widgets\ExpenseOverviewStats;
use App\Filament\Resources\ExpenseResource\Widgets\MonthlyExpenseChart;
use App\Models\Expense;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New Expense / Udhar'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ExpenseOverviewStats::class,
            MonthlyExpenseChart::class,
        ];
    }

    public function getTabs(): array
    {
        $pendingCount = Expense::where('type', 'udhar_given')->where('status', 'pending')->count();

        return [
            'all' => Tab::make('All')
                ->badge(Expense::count()),

            'this_month' => Tab::make('This Month')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('expense_date', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ])),

            'expenses' => Tab::make('Regular Expenses')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'expense')),

            'udhar_pending' => Tab::make('Udhar Pending (લેવાના બાકી)')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'udhar_given')->where('status', 'pending'))
                ->badge($pendingCount > 0 ? (string) $pendingCount : null)
                ->badgeColor('danger'),

            'udhar_settled' => Tab::make('Udhar Settled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'udhar_received')->orWhere(fn ($q) => $q->where('type', 'udhar_given')->where('status', 'settled'))),
        ];
    }
}
