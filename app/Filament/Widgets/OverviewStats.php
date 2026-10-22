<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\IpoApplication;
use App\Models\Source;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $accounts = Account::where('is_active', true)->get();
        $totalBalance = (float) $accounts->sum('total_balance');
        $availableBalance = (float) $accounts->sum('current_balance');
        $totalDue = (float) Source::all()->sum('balance_due');

        $pendingApps = IpoApplication::where('status', 'applied')->get();
        $blockedInIpos = (float) $pendingApps->sum('amount');
        $pendingCount = $pendingApps->count();

        $soldApps = IpoApplication::where('status', 'sold')->get();
        $totalProfit = (float) $soldApps->sum(fn (IpoApplication $a) => (float) $a->profit);

        return [
            Stat::make('Total Account Value (કુલ રકમ)', '₹'.number_format($totalBalance, 2))
                ->description('Total capital across Bank & Cash')
                ->color($totalBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-scale'),

            Stat::make('Blocked in IPOs (IPO માં બ્લોક)', '₹'.number_format($blockedInIpos, 2))
                ->description("{$pendingCount} application(s) pending outcome")
                ->color('primary')
                ->icon('heroicon-o-lock-closed'),

            Stat::make('Available to Use (વાપરવા માટે ઉપલબ્ધ)', '₹'.number_format($availableBalance, 2))
                ->description('Free balance for new applications')
                ->color($availableBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-wallet'),

            Stat::make('Total Profit (કુલ નફો)', '₹'.number_format($totalProfit, 2))
                ->description($totalProfit >= 0 ? 'Net profit from sold shares' : 'Net loss from sold shares')
                ->color($totalProfit >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrow-trending-up'),

            Stat::make('Due to Sources (ચૂકવવાના બાકી)', '₹'.number_format($totalDue, 2))
                ->description('Payable to capital sources')
                ->color($totalDue > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-arrow-up-circle'),
        ];
    }
}
