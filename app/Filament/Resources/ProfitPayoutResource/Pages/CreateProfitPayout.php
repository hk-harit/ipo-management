<?php

namespace App\Filament\Resources\ProfitPayoutResource\Pages;

use App\Filament\Resources\ProfitPayoutResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProfitPayout extends CreateRecord
{
    protected static string $resource = ProfitPayoutResource::class;

    /** Paying more than is pending is allowed (an advance), but never silently. */
    protected function afterCreate(): void
    {
        $payout = $this->record;
        $pending = $payout->source_id
            ? $payout->source?->profit_share_pending
            : $payout->applicant?->commission_pending;

        if ($pending !== null && $pending < 0) {
            Notification::make()
                ->warning()
                ->title('Paid more than was pending')
                ->body("{$payout->payee_name} has now been paid ₹".number_format(abs($pending), 2).' more than their profit share earned so far.')
                ->persistent()
                ->send();
        }
    }
}
