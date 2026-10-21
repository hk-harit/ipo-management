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
        $totalBalance = (float) $accounts->sum('current_balance');
        $totalDue = (float) Source::all()->sum('balance_due');

        $pendingApps = IpoApplication::where('status', 'applied')->get();
        $blockedInIpos = (float) $pendingApps->sum('amount');
        $pendingCount = $pendingApps->count();

        $soldApps = IpoApplication::where('status', 'sold')->get();
        $totalProfit = (float) $soldApps->sum(fn (IpoApplication $a) => (float) $a->profit);

        $allottedCount = IpoApplication::whereIn('status', ['allotted', 'partial_allotted', 'sold'])->count();
        $finishedCount = IpoApplication::whereIn('status', ['allotted', 'partial_allotted', 'refunded', 'sold'])->count();
        $allotmentRate = $finishedCount > 0 ? round(($allottedCount / $finishedCount) * 100, 1) : 0;

        return [
            Stat::make('Blocked in IPOs (રોકાયેલા)', '₹'.number_format($blockedInIpos, 2))
                ->description("{$pendingCount} application(s) pending outcome")
                ->color('primary')
                ->icon('heroicon-o-lock-closed'),

            Stat::make('Total Profit (કુલ નફો)', '₹'.number_format($totalProfit, 2))
                ->description($totalProfit >= 0 ? 'Net profit from sold shares' : 'Net loss from sold shares')
                ->color($totalProfit >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrow-trending-up'),

            Stat::make('Allotment Rate (સફળતા દર)', "{$allotmentRate}%")
                ->description("{$allottedCount} of {$finishedCount} allotted")
                ->color($allotmentRate > 25 ? 'success' : ($allotmentRate > 0 ? 'info' : 'gray'))
                ->icon('heroicon-o-check-badge'),

            Stat::make('Available Balance (ઉપલબ્ધ બેલેન્સ)', '₹'.number_format($totalBalance, 2))
                ->description('Across active bank & cash accounts')
                ->color($totalBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-wallet'),

            Stat::make('Due to Sources (ચૂકવવાના બાકી)', '₹'.number_format($totalDue, 2))
                ->description('Payable to capital sources')
                ->color($totalDue > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-arrow-up-circle'),
        ];
    }
}
