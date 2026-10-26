<?php

namespace App\Filament\Support;

use App\Models\IpoApplication;
use Filament\Notifications\Notification;

/**
 * Heads-ups after an application is saved. None of these block anything — the
 * same person legitimately applies twice to one IPO (retail + HNI), and an
 * account may be topped up a day later — but they should never be a surprise.
 */
class ApplicationWarnings
{
    public static function notify(IpoApplication $application, bool $checkBalance = true): void
    {
        // The account that actually pays: the sub-account when one is chosen.
        $account = $application->sub_account_id ? $application->subAccount : $application->account;

        if ($checkBalance && $account && $account->current_balance < 0) {
            Notification::make()
                ->warning()
                ->title('Account is overdrawn')
                ->body("'{$account->name}' balance is now ₹".number_format($account->current_balance, 2).'. Add funds or move money in before the mandate is collected.')
                ->persistent()
                ->send();
        }

        $others = IpoApplication::query()
            ->where('ipo_id', $application->ipo_id)
            ->where('applicant_id', $application->applicant_id)
            ->whereKeyNot($application->getKey())
            ->count();

        if ($others > 0) {
            Notification::make()
                ->info()
                ->title('Applicant already applied to this IPO')
                ->body("{$application->applicant?->name} has {$others} other application(s) for this IPO. Fine for retail + HNI, but check it is not a double entry.")
                ->send();
        }
    }
}
