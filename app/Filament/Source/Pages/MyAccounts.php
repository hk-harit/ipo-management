<?php

namespace App\Filament\Source\Pages;

use App\Filament\Source\Concerns\InteractsWithSource;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * The accounts this source's money has passed through, with the balance each
 * one currently holds.
 *
 * Note the balance shown is the account's *whole* balance, not this source's
 * portion of it — an account usually pools money from several sources.
 */
class MyAccounts extends Page
{
    use InteractsWithSource;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?string $title = 'Accounts';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.source.pages.my-accounts';

    public function getSubheading(): ?string
    {
        return 'The accounts your money went into, and what each one holds today.';
    }

    public function accounts(): Collection
    {
        return $this->source()->fundedAccounts();
    }
}
