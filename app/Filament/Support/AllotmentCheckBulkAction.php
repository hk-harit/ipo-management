<?php

namespace App\Filament\Support;

use App\Services\AllotmentCheck\AllotmentCheckService;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Check Allotment" bulk action — shared by the top-level Applications list
 * and the per-IPO relation manager.
 * Runs directly so it works seamlessly on environments without background
 * queue daemons (like Hostinger Business Web Hosting).
 */
class AllotmentCheckBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('checkAllotment')
            ->label('Check Allotment')
            ->icon('heroicon-o-magnifying-glass-circle')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Check Allotment for Selected Applications')
            ->modalDescription("Looks up each selected application's allotment status with its IPO's configured registrar, and immediately updates status, shares allotted, and refund amount. Applications whose IPO has no registrar configured, or whose applicant has no PAN on file, will be skipped.")
            ->action(function (Collection $records, AllotmentCheckService $service) {
                $counts = $service->checkManyDirect($records);

                $summary = "Checked {$counts['total']} application(s): "
                    ."{$counts['allotted']} Allotted · "
                    ."{$counts['refunded']} Refunded";

                if ($counts['not_found'] > 0) {
                    $summary .= " · {$counts['not_found']} Not Found";
                }
                if ($counts['skipped'] > 0) {
                    $summary .= " · {$counts['skipped']} Skipped";
                }
                if ($counts['error'] > 0) {
                    $summary .= " · {$counts['error']} Errors";
                    if (! empty($counts['errors'])) {
                        $summary .= ' ('.implode(', ', array_slice($counts['errors'], 0, 2)).')';
                    }
                }

                Notification::make()
                    ->title('Allotment check completed')
                    ->body($summary)
                    ->color($counts['allotted'] > 0 ? 'success' : ($counts['error'] > 0 ? 'warning' : 'info'))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
