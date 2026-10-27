<?php

namespace App\Filament\Pages;

use App\Services\AccountOverview;
use Filament\Pages\Page;

/**
 * "Which account paid for how much, and whose money was in it" — one screen.
 */
class AccountOverviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Account Overview';

    protected static ?string $title = 'Account Overview';

    protected static ?string $slug = 'account-overview';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.account-overview';

    /** 'all' or an IPO id. Starts on the IPO that was applied to most recently. */
    public string $ipo = '';

    public function mount(): void
    {
        $this->ipo = (string) ((new AccountOverview)->ipoOptions()->first()['id'] ?? 'all');
    }

    public function getSubheading(): ?string
    {
        return 'For each account: how many applications were made, how much money each source gave, and what is left.';
    }

    public function overview(): array
    {
        return (new AccountOverview)->build($this->ipo === 'all' || $this->ipo === '' ? null : (int) $this->ipo);
    }

    public function ipoOptions()
    {
        return (new AccountOverview)->ipoOptions();
    }
}
