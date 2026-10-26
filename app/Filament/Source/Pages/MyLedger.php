<?php

namespace App\Filament\Source\Pages;

use App\Filament\Source\Concerns\InteractsWithSource;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * The source's own passbook: every rupee they handed in, every rupee paid back,
 * in date order, with the outstanding balance after each entry.
 */
class MyLedger extends Page
{
    use InteractsWithSource;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Money Given & Returned';

    protected static ?string $title = 'Money Given & Returned';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.source.pages.my-ledger';

    public function getSubheading(): ?string
    {
        return 'Your passbook — what you handed in, what came back, and the running balance.';
    }

    public function ledger(): Collection
    {
        return $this->source()->buildLedger();
    }

    /** Profit-share payments made to this source, newest first. */
    public function payouts(): Collection
    {
        return $this->source()->profitPayouts()->orderByDesc('payout_date')->orderByDesc('id')->get();
    }

    public function summary(): array
    {
        $source = $this->source();

        return [
            'given' => $source->total_given,
            'returned' => $source->total_returned,
            'due' => $source->balance_due,
        ];
    }
}
