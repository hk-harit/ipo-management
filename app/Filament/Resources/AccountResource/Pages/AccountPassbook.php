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

    public function unblockApplication(int $applicationId): void
    {
        $app = \App\Models\IpoApplication::where('account_id', $this->record->id)
            ->where('id', $applicationId)
            ->first();

        if (! $app) {
            return;
        }

        $app->update([
            'status' => 'refunded',
            'return_amount' => $app->amount,
            'return_date' => now()->toDateString(),
            'shares_allotted' => null,
        ]);

        $this->record->refresh();

        \Filament\Notifications\Notification::make()
            ->title('Mandate Unblocked / Refunded!')
            ->body('₹' . number_format($app->amount, 2) . ' credited back to ' . $this->record->name . ' and logged in passbook.')
            ->success()
            ->send();
    }
}
