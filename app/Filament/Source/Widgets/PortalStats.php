<?php

namespace App\Filament\Source\Widgets;

use App\Filament\Source\Concerns\InteractsWithSource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The five numbers a source actually cares about, in the order they ask them:
 * how much have I put in, how much came back, what is still with you, how much
 * of mine is locked in IPOs right now, and what did I earn.
 */
class PortalStats extends BaseWidget
{
    use InteractsWithSource;

    protected static ?int $sort = -2;

    protected function getStats(): array
    {
        $source = $this->source();

        $given = $source->total_given;
        $returned = $source->total_returned;
        $due = $source->balance_due;
        $blocked = $source->blocked_in_ipos;
        $profitShare = $source->total_profit_share_due;

        return [
            Stat::make('You Gave', $this->money($given))
                ->description('Total handed in so far')
                ->color('success')
                ->icon('heroicon-o-arrow-down-circle'),

            Stat::make('Paid Back', $this->money($returned))
                ->description('Total returned to you')
                ->color('gray')
                ->icon('heroicon-o-arrow-up-circle'),

            Stat::make('Still With Us', $this->money($due))
                ->description($due > 0 ? 'Outstanding balance' : 'Fully settled')
                ->color($due > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-scale'),

            Stat::make('Locked in IPOs', $this->money($blocked))
                ->description('Applied, result awaited')
                ->color('primary')
                ->icon('heroicon-o-document-check'),

            Stat::make('Your Profit Share', $this->money($profitShare))
                ->description($source->profit_share_percent.'% of profit on sold IPOs')
                ->color('info')
                ->icon('heroicon-o-banknotes'),
        ];
    }

    private function money(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }
}
