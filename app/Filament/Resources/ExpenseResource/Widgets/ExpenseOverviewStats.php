<?php

namespace App\Filament\Resources\ExpenseResource\Widgets;

use App\Models\Expense;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExpenseOverviewStats extends BaseWidget
{
    protected function getStats(): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfMonth = $now->copy()->endOfMonth()->toDateString();

        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth()->toDateString();

        $thisMonthExpense = (float) Expense::where('type', 'expense')
            ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $lastMonthExpense = (float) Expense::where('type', 'expense')
            ->whereBetween('expense_date', [$startOfLastMonth, $endOfLastMonth])
            ->sum('amount');

        $pendingUdhar = (float) Expense::where('type', 'udhar_given')
            ->where('status', 'pending')
            ->sum('amount');

        $pendingPeopleCount = Expense::where('type', 'udhar_given')
            ->where('status', 'pending')
            ->distinct('person_name')
            ->count('person_name');

        $udharReceived = (float) Expense::where('type', 'udhar_received')
            ->sum('amount');

        $totalAllTime = (float) Expense::where('type', 'expense')->sum('amount');

        $diff = $thisMonthExpense - $lastMonthExpense;
        $trendDesc = $lastMonthExpense > 0
            ? ($diff > 0 ? '+₹'.number_format($diff, 2).' vs last month' : '-₹'.number_format(abs($diff), 2).' vs last month')
            : 'Current month total';

        return [
            Stat::make('This Month Expenses', '₹'.number_format($thisMonthExpense, 2))
                ->description($trendDesc)
                ->color($diff > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-calendar-days'),

            Stat::make('Udhar Given (Pending)', '₹'.number_format($pendingUdhar, 2))
                ->description($pendingPeopleCount > 0 ? "Pending from {$pendingPeopleCount} person(s)" : 'No pending loans')
                ->color($pendingUdhar > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-arrow-up-right'),

            Stat::make('Udhar Recovered', '₹'.number_format($udharReceived, 2))
                ->description('Total money received back')
                ->color('info')
                ->icon('heroicon-o-arrow-down-left'),

            Stat::make('All-Time Expenses', '₹'.number_format($totalAllTime, 2))
                ->description('Total regular expenses')
                ->color('gray')
                ->icon('heroicon-o-banknotes'),
        ];
    }
}
