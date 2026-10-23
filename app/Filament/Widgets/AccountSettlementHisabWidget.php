<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\AccountTransfer;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class AccountSettlementHisabWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.account-settlement-hisab-widget';

    public ?int $settleFromAccountId = null;
    public ?int $settleToAccountId = null;
    public ?float $settleAmount = null;
    public ?string $settleNotes = null;
    public bool $showSettleModal = false;

    /**
     * Compute inter-account transfer settlements.
     * Returns a collection of pairs with amounts sent, returned, and net due.
     */
    public function getSettlements(): Collection
    {
        $accounts = Account::where('is_active', true)->get()->keyBy('id');
        $transfers = AccountTransfer::all();

        $pairs = [];

        foreach ($transfers as $tx) {
            $from = $tx->from_account_id;
            $to = $tx->to_account_id;

            if ($from == $to || ! isset($accounts[$from]) || ! isset($accounts[$to])) {
                continue;
            }

            // Canonical pair key so A->B and B->A are grouped together
            $minId = min($from, $to);
            $maxId = max($from, $to);
            $key = "{$minId}_{$maxId}";

            if (! isset($pairs[$key])) {
                $pairs[$key] = [
                    'account_1' => $accounts[$minId],
                    'account_2' => $accounts[$maxId],
                    'sent_1_to_2' => 0.0,
                    'sent_2_to_1' => 0.0,
                    'tx_count' => 0,
                    'last_transfer_date' => $tx->transfer_date,
                ];
            }

            if ($from === $minId) {
                $pairs[$key]['sent_1_to_2'] += (float) $tx->amount;
            } else {
                $pairs[$key]['sent_2_to_1'] += (float) $tx->amount;
            }

            $pairs[$key]['tx_count']++;

            if ($tx->transfer_date > $pairs[$key]['last_transfer_date']) {
                $pairs[$key]['last_transfer_date'] = $tx->transfer_date;
            }
        }

        $result = collect();

        foreach ($pairs as $p) {
            $diff = $p['sent_1_to_2'] - $p['sent_2_to_1'];

            if ($diff > 0) {
                // Account 1 sent more to Account 2. Account 2 owes Account 1.
                $giver = $p['account_1'];
                $receiver = $p['account_2'];
                $totalGiven = $p['sent_1_to_2'];
                $totalReturned = $p['sent_2_to_1'];
                $dueAmount = $diff;
            } elseif ($diff < 0) {
                // Account 2 sent more to Account 1. Account 1 owes Account 2.
                $giver = $p['account_2'];
                $receiver = $p['account_1'];
                $totalGiven = $p['sent_2_to_1'];
                $totalReturned = $p['sent_1_to_2'];
                $dueAmount = abs($diff);
            } else {
                // Completely settled
                $giver = $p['account_1'];
                $receiver = $p['account_2'];
                $totalGiven = $p['sent_1_to_2'];
                $totalReturned = $p['sent_2_to_1'];
                $dueAmount = 0.0;
            }

            $result->push([
                'giver' => $giver,
                'receiver' => $receiver,
                'total_given' => $totalGiven,
                'total_returned' => $totalReturned,
                'due_amount' => $dueAmount,
                'is_settled' => ($dueAmount <= 0.01),
                'tx_count' => $p['tx_count'],
                'last_date' => $p['last_transfer_date'],
            ]);
        }

        // Show pending dues first (highest due amount first), then settled ones
        return $result->sortByDesc('due_amount')->values();
    }

    public function openSettleModal(int $fromId, int $toId, float $amount): void
    {
        $this->settleFromAccountId = $fromId;
        $this->settleToAccountId = $toId;
        $this->settleAmount = $amount;
        $this->settleNotes = 'Settlement: transfer back pending balance';
        $this->showSettleModal = true;
    }

    public function closeSettleModal(): void
    {
        $this->showSettleModal = false;
        $this->reset(['settleFromAccountId', 'settleToAccountId', 'settleAmount', 'settleNotes']);
    }

    public function confirmSettle(): void
    {
        if (! $this->settleFromAccountId || ! $this->settleToAccountId || ! $this->settleAmount || $this->settleAmount <= 0) {
            Notification::make()
                ->title('Invalid Amount')
                ->body('Please enter a valid amount.')
                ->danger()
                ->send();
            return;
        }

        $fromAcc = Account::find($this->settleFromAccountId);
        $toAcc = Account::find($this->settleToAccountId);

        AccountTransfer::create([
            'user_id' => auth()->id(),
            'from_account_id' => $this->settleFromAccountId,
            'to_account_id' => $this->settleToAccountId,
            'amount' => $this->settleAmount,
            'transfer_date' => now()->toDateString(),
            'notes' => $this->settleNotes ?: 'Settlement transfer back',
        ]);

        $amountFormatted = number_format($this->settleAmount, 2);

        Notification::make()
            ->title('Transfer Recorded & Settled!')
            ->body("₹{$amountFormatted} transferred from '{$fromAcc?->name}' back to '{$toAcc?->name}'. Hisab updated!")
            ->success()
            ->send();

        $this->closeSettleModal();
    }
}
