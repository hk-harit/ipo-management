<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class AccountPassbook extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AccountResource::class;

    protected static string $view = 'filament.resources.account-resource.pages.account-passbook';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->name.' — Passbook';
    }

    public function ledger(): \Illuminate\Support\Collection
    {
        return $this->record->buildLedger();
    }

    public function blockedApplications(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->record->blockedIpoApplications()
            ->with(['ipo', 'applicant'])
            ->latest('applied_date')
            ->get();
    }
}
