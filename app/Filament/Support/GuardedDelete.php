<?php

namespace App\Filament\Support;

use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Delete actions for models that use BlocksDeleteWithHistory. Filament's stock
 * bulk delete swallows a vetoed delete and still says "Deleted", so these check
 * first, say why, and cancel instead.
 */
class GuardedDelete
{
    /** For an edit page's header. */
    public static function page(): PageDeleteAction
    {
        return PageDeleteAction::make()->before(function (PageDeleteAction $action, Model $record) {
            if (static::refuse([$record])) {
                $action->cancel();
            }
        });
    }

    /** For a row action in a table or relation manager. */
    public static function row(): TableDeleteAction
    {
        return TableDeleteAction::make()->before(function (TableDeleteAction $action, Model $record) {
            if (static::refuse([$record])) {
                $action->cancel();
            }
        });
    }

    public static function bulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()->before(function (DeleteBulkAction $action, Collection $records) {
            if (static::refuse($records->all())) {
                $action->cancel();
            }
        });
    }

    /** Sends a notification and returns true when any record cannot be deleted. */
    private static function refuse(array $records): bool
    {
        $reasons = [];

        foreach ($records as $record) {
            if ($reason = $record->deletionBlockedReason()) {
                $reasons[] = ($record->name ?? '#'.$record->getKey()).': '.$reason;
            }
        }

        if ($reasons === []) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title('Cannot delete — it has history')
            ->body(implode("\n", array_slice($reasons, 0, 5)))
            ->persistent()
            ->send();

        return true;
    }
}
