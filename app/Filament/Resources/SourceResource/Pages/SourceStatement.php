<?php

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * A statement for one source: what they gave, what came back, every application
 * made with their money, the profit share and what has been paid. Built to print
 * (or "Save as PDF" from the browser) and to paste into WhatsApp.
 */
class SourceStatement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SourceResource::class;

    protected static string $view = 'filament.resources.source-resource.pages.source-statement';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Statement — '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print / Save as PDF')
                ->icon('heroicon-o-printer')
                ->extraAttributes(['onclick' => 'window.print()'])
                ->action(fn () => null),
            Action::make('back')
                ->label('Back to Source')
                ->color('gray')
                ->url(SourceResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    /** Headline figures, all-time. */
    public function summary(): array
    {
        $source = $this->record;

        return [
            'given' => $source->total_given,
            'returned' => $source->total_returned,
            'due' => $source->balance_due,
            'earned' => $source->total_profit_share_due,
            'paid' => $source->profit_share_paid,
            'pending' => $source->profit_share_pending,
            'payable' => $source->total_payable,
        ];
    }

    public function ledger(): Collection
    {
        return $this->record->buildLedger();
    }

    public function applications(): Collection
    {
        return $this->record->ipoApplications()
            ->with(['ipo', 'applicant', 'sales', 'source'])
            ->orderBy('applied_date')
            ->orderBy('id')
            ->get();
    }

    public function payouts(): Collection
    {
        return $this->record->profitPayouts()->orderBy('payout_date')->orderBy('id')->get();
    }

    /** A short plain-text version for WhatsApp. */
    public function whatsappText(): string
    {
        $s = $this->summary();
        $money = fn (float $v) => '₹'.number_format($v, 2);

        return implode("\n", [
            '*IPO Statement — '.$this->record->name.'*',
            now()->format('d M Y'),
            '',
            'You gave: '.$money($s['given']),
            'Paid back: '.$money($s['returned']),
            'Still with us: '.$money($s['due']),
            '',
            'Profit share earned: '.$money($s['earned']),
            'Profit paid: '.$money($s['paid']),
            'Profit pending: '.$money($s['pending']),
            '',
            '*Total payable to you: '.$money($s['payable']).'*',
        ]);
    }
}
