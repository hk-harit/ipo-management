<?php

namespace App\Filament\Support;

use App\Jobs\CheckAllotmentJob;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * "Check Allotment" bulk action — shared by the top-level Applications list
 * and the per-IPO relation manager, so selecting a batch of applications and
 * running them through their IPO's configured registrar works the same
 * everywhere. Skips (rather than fails) anything not set up for it, so it's
 * always safe to select a mixed batch.
 *
 * Each application's check runs in its own queued CheckAllotmentJob rather
 * than inline: a registrar lookup involves OCR + a few HTTP round trips, and
 * running that in a loop inside the request that handled the click would
 * block the panel (and risk a timeout) for anything more than a couple of
 * applications. The jobs run as one Bus::batch so a single notification can
 * report the combined outcome once they've all finished.
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
            ->modalDescription("Looks up each selected application's allotment status with its IPO's configured registrar, and updates status / shares allotted / amount returned automatically. Runs in the background — you'll get a notification when it's done. Applications whose IPO has no registrar configured, or whose applicant has no PAN on file, are skipped.")
            ->action(function (Collection $records) {
                $ids = $records->pluck('id')->all();
                $userId = auth()->id();

                Bus::batch(
                    array_map(fn (int $id) => new CheckAllotmentJob($id), $ids)
                )
                    ->name('Check allotment: '.count($ids).' application(s)')
                    ->then(function (Batch $batch) use ($userId) {
                        $counts = collect(['updated', 'not_found', 'skipped', 'error'])
                            ->mapWithKeys(fn (string $status) => [
                                $status => (int) Cache::pull("allotment-check:{$batch->id}:{$status}", 0),
                            ]);

                        $summary = "Updated {$counts['updated']} · Not found {$counts['not_found']} · Skipped {$counts['skipped']}"
                            .($counts['error'] > 0 ? " · Errors {$counts['error']}" : '');

                        if ($userId && $user = User::find($userId)) {
                            Notification::make()
                                ->title('Allotment check complete')
                                ->body($summary)
                                ->color($counts['error'] > 0 ? 'warning' : 'success')
                                ->sendToDatabase($user);
                        }
                    })
                    ->dispatch();

                Notification::make()
                    ->title('Allotment check queued')
                    ->body(count($ids)." application(s) queued for background checking — you'll get a notification here when it's done.")
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
