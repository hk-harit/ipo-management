<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory, BelongsToUser;

    protected $fillable = [
        'user_id',
        'name',
        'holder_name',
        'type',
        'bank_name',
        'account_number',
        'opening_balance',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function fundTransactions(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    public function ipoApplications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    public function blockedIpoApplications(): HasMany
    {
        return $this->hasMany(IpoApplication::class)->where('status', 'applied');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'from_account_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'to_account_id');
    }

    /**
     * Current balance, derived from opening balance plus every transaction
     * that has touched this account. Never stored directly, so it can't drift.
     */
    public function getCurrentBalanceAttribute(): float
    {
        $fundsIn = (float) $this->fundTransactions()->where('type', 'given')->sum('amount');
        $fundsOut = (float) $this->fundTransactions()->where('type', 'returned')->sum('amount');
        $applied = (float) $this->ipoApplications()->sum('amount');
        $returned = (float) $this->ipoApplications()->sum('return_amount');
        $expensesOut = (float) $this->expenses()->whereIn('type', ['expense', 'udhar_given'])->sum('amount');
        $expensesIn = (float) $this->expenses()->where('type', 'udhar_received')->sum('amount');
        $netExpenses = $expensesOut - $expensesIn;
        $transfersOut = (float) $this->transfersOut()->sum('amount');
        $transfersIn = (float) $this->transfersIn()->sum('amount');

        return (float) $this->opening_balance
            + $fundsIn - $fundsOut
            - $applied + $returned
            - $netExpenses
            - $transfersOut + $transfersIn;
    }

    /**
     * Amount currently blocked under ASBA / lien in pending IPO applications for this account.
     */
    public function getBlockedInIposAttribute(): float
    {
        return (float) $this->ipoApplications()->where('status', 'applied')->sum('amount');
    }

    /**
     * Total account worth = Liquid available balance + Amount blocked in pending IPOs.
     */
    public function getTotalBalanceAttribute(): float
    {
        return (float) $this->current_balance + (float) $this->blocked_in_ipos;
    }

    /**
     * A chronological, running-balance ledger of everything that has ever
     * touched this account — a "passbook" view. Each entry: date, description,
     * debit, credit, balance (after the entry).
     */
    public function buildLedger(): \Illuminate\Support\Collection
    {
        $entries = collect();

        foreach ($this->fundTransactions()->with('source')->get() as $tx) {
            $entries->push([
                'date' => $tx->transaction_date,
                'created_at' => $tx->created_at,
                'description' => $tx->type === 'given'
                    ? 'Received from '.($tx->source->name ?? 'source')
                    : 'Paid back to '.($tx->source->name ?? 'source'),
                'debit' => $tx->type === 'returned' ? (float) $tx->amount : 0.0,
                'credit' => $tx->type === 'given' ? (float) $tx->amount : 0.0,
            ]);
        }

        foreach ($this->ipoApplications()->with(['ipo', 'applicant'])->get() as $app) {
            $label = ($app->ipo->name ?? 'IPO').' ('.($app->applicant->name ?? 'applicant').')';

            $entries->push([
                'date' => $app->applied_date,
                'created_at' => $app->created_at,
                'description' => 'Applied: '.$label,
                'debit' => (float) $app->amount,
                'credit' => 0.0,
            ]);

            if ($app->return_date && $app->return_amount !== null) {
                $entries->push([
                    'date' => $app->return_date,
                    'created_at' => $app->updated_at,
                    'description' => ($app->status === 'sold' ? 'Sale proceeds: ' : 'Refund: ').$label,
                    'debit' => 0.0,
                    'credit' => (float) $app->return_amount,
                ]);
            }
        }

        foreach ($this->expenses()->get() as $expense) {
            $isIncome = ($expense->type === 'udhar_received');
            $entries->push([
                'date' => $expense->expense_date,
                'created_at' => $expense->created_at,
                'description' => $expense->display_description,
                'debit' => $isIncome ? 0.0 : (float) $expense->amount,
                'credit' => $isIncome ? (float) $expense->amount : 0.0,
            ]);
        }

        foreach ($this->transfersOut()->with('toAccount')->get() as $transfer) {
            $entries->push([
                'date' => $transfer->transfer_date,
                'created_at' => $transfer->created_at,
                'description' => 'Transferred to '.($transfer->toAccount->name ?? 'account'),
                'debit' => (float) $transfer->amount,
                'credit' => 0.0,
            ]);
        }

        foreach ($this->transfersIn()->with('fromAccount')->get() as $transfer) {
            $entries->push([
                'date' => $transfer->transfer_date,
                'created_at' => $transfer->created_at,
                'description' => 'Transfer received from '.($transfer->fromAccount->name ?? 'account'),
                'debit' => 0.0,
                'credit' => (float) $transfer->amount,
            ]);
        }

        $entries = $entries->sortBy([
            fn ($a, $b) => $a['date'] <=> $b['date'],
            fn ($a, $b) => $a['created_at'] <=> $b['created_at'],
        ])->values();

        $balance = (float) $this->opening_balance;

        return $entries->map(function ($entry) use (&$balance) {
            $balance += $entry['credit'] - $entry['debit'];
            $entry['balance'] = $balance;

            return $entry;
        });
    }
}
