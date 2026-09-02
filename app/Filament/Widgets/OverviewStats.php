<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\IpoApplication;
use App\Models\Source;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends BaseWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        $accounts = Account::where('is_active', true)->get();
        $totalBalance = $accounts->sum('current_balance');
        $cashInHand = $accounts->where('type', 'cash')->sum('current_balance');
        $totalDue = Source::all()->sum('balance_due');
        $blockedInIpos = (float) IpoApplication::where('status', 'applied')->sum('amount');

        return [
            Stat::make('Total Balance', '₹'.number_format($totalBalance, 2))
                ->description('Across all accounts')
                ->color($totalBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-scale'),

            Stat::make('Cash in Hand', '₹'.number_format($cashInHand, 2))
                ->color('gray')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Blocked in IPOs', '₹'.number_format($blockedInIpos, 2))
                ->description('Applied, awaiting result')
                ->color('primary')
                ->icon('heroicon-o-document-check'),

            Stat::make('Due to Sources', '₹'.number_format($totalDue, 2))
                ->color('warning')
                ->icon('heroicon-o-arrow-up-circle'),
        ];
    }
}
