<?php

namespace App\Filament\Support;

use App\Models\IpoApplication;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class RefundIpoApplicationAction
{
    public static function make(): Action
    {
        return Action::make('markRefunded')
            ->label('Unblock / Refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->tooltip('Unblock mandate / Mark application as refunded to account')
            ->visible(fn (IpoApplication $record) => $record->status === 'applied')
            ->requiresConfirmation()
            ->modalHeading(fn (IpoApplication $record) => "Unblock Mandate / Refund — " . ($record->ipo?->name ?? 'IPO'))
            ->modalDescription(fn (IpoApplication $record) => "This will mark the application as refunded and credit ₹" . number_format($record->amount, 2) . " back to '" . ($record->account?->name ?? 'the account') . "'. A refund entry will be added to the account's passbook.")
            ->form([
                DatePicker::make('return_date')
                    ->label('Refund Date (મેન્ડેટ છૂટ્યાની તારીખ)')
                    ->default(now())
                    ->required(),
                TextInput::make('return_amount')
                    ->label('Refund Amount (પરત જમા રકમ)')
                    ->numeric()
                    ->prefix('₹')
                    ->default(fn (IpoApplication $record) => $record->amount)
                    ->helperText('This amount will be credited back to the payment account.')
                    ->required(),
            ])
            ->action(function (IpoApplication $record, array $data) {
                $returnAmount = (float) $data['return_amount'];
                $returnDate = $data['return_date'];

                $record->update([
                    'status' => 'refunded',
                    'return_amount' => $returnAmount,
                    'return_date' => $returnDate,
                    'shares_allotted' => null,
                ]);

                $accountName = $record->account?->name ?? 'Account';

                Notification::make()
                    ->title('Mandate Unblocked & Refunded')
                    ->body('₹' . number_format($returnAmount, 2) . ' credited back to ' . $accountName . ' passbook.')
                    ->success()
                    ->send();
            });
    }

    public static function bulkMake(): BulkAction
    {
        return BulkAction::make('markRefundedBulk')
            ->label('Mark as Refunded (Unblock)')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Mark Selected Applications as Refunded')
            ->modalDescription('All selected applications will be marked as Refunded. Their full application amounts will be unblocked and credited back to their respective bank accounts.')
            ->action(function (Collection $records) {
                $totalAmount = 0;
                $count = 0;

                foreach ($records as $record) {
                    $refundAmt = (float) ($record->return_amount ?: $record->amount);
                    $record->update([
                        'status' => 'refunded',
                        'return_amount' => $refundAmt,
                        'return_date' => $record->return_date ?: now()->toDateString(),
                        'shares_allotted' => null,
                    ]);
                    $count++;
                    $totalAmount += $refundAmt;
                }

                Notification::make()
                    ->title("{$count} Application(s) Refunded")
                    ->body('₹' . number_format($totalAmount, 2) . ' total unblocked and credited back to bank account passbooks.')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
