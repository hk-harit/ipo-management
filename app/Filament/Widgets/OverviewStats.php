<?php

namespace App\Filament\Widgets;

use App\Models\IpoApplication;
use App\Services\DashboardSummary;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $summary = new DashboardSummary;
        $totals = $summary->totals();

        $period = $this->filters['period'] ?? 'all';
        $profit = $summary->profit(...DashboardSummary::periodRange($period));

        $pending = IpoApplication::where('status', 'applied')->count();
        $demat = IpoApplication::whereIn('status', ['allotted', 'partial_allotted'])->count();

        return [
            Stat::make('Total Funds (કુલ મૂડી)', $this->money($totals['total_funds']))
                ->description('Available + Blocked + In Demat')
                ->color($totals['total_funds'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-scale'),

            Stat::make('Available (ઉપલબ્ધ)', $this->money($totals['available']))
                ->description('Free to use across bank & cash')
                ->color($totals['available'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-wallet'),

            Stat::make('Blocked in IPOs (IPO માં બ્લોક)', $this->money($totals['blocked']))
                ->description("{$pending} application(s) awaiting result")
                ->color('primary')
                ->icon('heroicon-o-lock-closed'),

            Stat::make('In Demat (ડીમેટમાં, cost)', $this->money($totals['invested']))
                ->description("{$demat} allotted, awaiting sale")
                ->color('warning')
                ->icon('heroicon-o-rectangle-stack'),

            Stat::make('Net Position (ચોખ્ખી સ્થિતિ)', $this->money($totals['net_position']))
                ->description('Funds + udhar '.$this->money($totals['udhar_receivable'], 0).' − sources '.$this->money($totals['owed_to_sources'], 0).' − unpaid profit '.$this->money($totals['profit_owed'], 0))
                ->color($totals['net_position'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Profit — '.(DashboardSummary::PERIODS[$period] ?? 'All time'), $this->money($profit['profit']))
                ->description('Yours '.$this->money($profit['your_share'], 0).' · Sources '.$this->money($profit['source_share'], 0).' · Applicants '.$this->money($profit['applicant_share'], 0))
                ->color($profit['profit'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrow-trending-up'),
        ];
    }

    private function money(float $amount, int $decimals = 2): string
    {
        return ($amount < 0 ? '−' : '').'₹'.number_format(abs($amount), $decimals);
    }
}
