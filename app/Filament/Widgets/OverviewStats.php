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
        $accounts = Account::hasSubAccountColumn()
            ? Account::mainAccounts()->where('is_active', true)->get()
            : Account::where('is_active', true)->get();

        $availableBalance = (float) $accounts->sum('current_balance');

        $pendingApps = IpoApplication::where('status', 'applied')->get();
        $blockedInIpos = (float) $pendingApps->sum('amount');
        $pendingCount = $pendingApps->count();

        // Total Bank & Cash capital is Available + Blocked under ASBA
        $totalBalance = $availableBalance + $blockedInIpos;

        $allottedApps = IpoApplication::whereIn('status', ['allotted', 'partial_allotted'])->get();
        $allottedAmount = (float) $allottedApps->sum('amount');
        $allottedCount = $allottedApps->count();

        $totalDue = (float) Source::all()->sum('balance_due');

        $soldApps = IpoApplication::where('status', 'sold')->get();
        $totalProfit = (float) $soldApps->sum(fn (IpoApplication $a) => (float) $a->profit);

        $stats = [
            Stat::make('Total Capital (કુલ બેંક/રોકડ મૂડી)', '₹'.number_format($totalBalance, 2))
                ->description('ઉપલબ્ધ (₹'.number_format($availableBalance, 0).') + બ્લોક (₹'.number_format($blockedInIpos, 0).')')
                ->color($totalBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-scale'),

            Stat::make('Available to Use (વાપરવા માટે ઉપલબ્ધ)', '₹'.number_format($availableBalance, 2))
                ->description('Free liquid balance across all accounts')
                ->color($availableBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-wallet'),

            Stat::make('Blocked in IPOs (IPO માં બ્લોક)', '₹'.number_format($blockedInIpos, 2))
                ->description("{$pendingCount} application(s) pending outcome")
                ->color('primary')
                ->icon('heroicon-o-lock-closed'),
        ];

        if ($allottedCount > 0) {
            $stats[] = Stat::make('Allotted in Demat (ડીમેટમાં અલોટેડ)', '₹'.number_format($allottedAmount, 2))
                ->description("{$allottedCount} app(s) awaiting listing/sale")
                ->color('warning')
                ->icon('heroicon-o-rectangle-stack');
        }

        $stats[] = Stat::make('Total Profit (કુલ નફો)', '₹'.number_format($totalProfit, 2))
            ->description($totalProfit >= 0 ? 'Net profit from sold shares' : 'Net loss from sold shares')
            ->color($totalProfit >= 0 ? 'success' : 'danger')
            ->icon('heroicon-o-arrow-trending-up');

        $stats[] = Stat::make('Due to Sources (ચૂકવવાના બાકી)', '₹'.number_format($totalDue, 2))
            ->description('Payable to capital sources')
            ->color($totalDue > 0 ? 'warning' : 'gray')
            ->icon('heroicon-o-arrow-up-circle');

        return $stats;
    }
}
