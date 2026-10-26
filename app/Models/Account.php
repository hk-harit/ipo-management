<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\BlocksDeleteWithHistory;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use Auditable, BelongsToUser, BlocksDeleteWithHistory, HasFactory;

    protected $fillable = [
        'user_id',
        'parent_id',
        'is_sub_account',
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
            'is_sub_account' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function scopeMainAccounts($query)
    {
        if (! static::hasSubAccountColumn()) {
            return $query;
        }

        return $query->where('is_sub_account', false);
    }

    public function scopeSubAccounts($query)
    {
        if (! static::hasSubAccountColumn()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('is_sub_account', true);
    }

    public function fundTransactions(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    protected static ?bool $hasSubAccountColumn = null;

    public static function hasSubAccountColumn(): bool
    {
        if (static::$hasSubAccountColumn === null) {
            try {
                static::$hasSubAccountColumn = Schema::hasColumn('ipo_applications', 'sub_account_id')
                    && Schema::hasColumn('accounts', 'is_sub_account');
            } catch (\Throwable) {
                static::$hasSubAccountColumn = false;
            }
        }

        return static::$hasSubAccountColumn;
    }

    public function ipoApplications(): HasMany
    {
        if (! empty($this->is_sub_account) && static::hasSubAccountColumn()) {
            return $this->hasMany(IpoApplication::class, 'sub_account_id');
        }

        // A main account's own applications — those paid from a sub-account are debited
        // from that sub-account instead (see subAccountIpoApplications for the rollup).
        $relation = $this->hasMany(IpoApplication::class, 'account_id');

        return static::hasSubAccountColumn() ? $relation->whereNull('sub_account_id') : $relation;
    }

    public function subAccountIpoApplications(): HasMany
    {
        if (! static::hasSubAccountColumn()) {
            return $this->hasMany(IpoApplication::class, 'account_id')->whereRaw('1 = 0');
        }

        return $this->hasMany(IpoApplication::class, 'account_id')->whereNotNull('sub_account_id');
    }

    public function blockedIpoApplications(): HasMany
    {
        return $this->ipoApplications()->where('status', 'applied');
    }

    public function deletionBlockedReason(): ?string
    {
        $applications = IpoApplication::query()->where(function ($q) {
            $q->where('account_id', $this->id)->orWhere('funding_account_id', $this->id);
            if (static::hasSubAccountColumn()) {
                $q->orWhere('sub_account_id', $this->id);
            }
        })->count();

        return $this->describeHistory([
            'IPO application(s)' => $applications,
            'fund transaction(s)' => $this->fundTransactions()->count(),
            'expense/udhar entr(ies)' => $this->expenses()->count(),
            'transfer(s)' => $this->transfersOut()->count() + $this->transfersIn()->count(),
            'profit payout(s)' => $this->profitPayouts()->count(),
            'sub-account(s)' => static::hasSubAccountColumn() ? $this->subAccounts()->count() : 0,
        ]);
    }

    public function profitPayouts(): HasMany
    {
        return $this->hasMany(ProfitPayout::class);
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
        $returned = (float) $this->ipoApplications()
            ->selectRaw("COALESCE(SUM(CASE WHEN return_amount IS NOT NULL THEN return_amount WHEN status = 'refunded' THEN amount ELSE 0 END), 0) as total")
            ->value('total');
        // Net proceeds of share sales (after charges) recorded as sales rows.
        $salesIn = (float) ApplicationSale::query()
            ->whereIn('ipo_application_id', $this->ipoApplications()->select('ipo_applications.id'))
            ->sum('net_amount');
        $payoutsOut = (float) $this->profitPayouts()->sum('amount');
        $expensesOut = (float) $this->expenses()->whereIn('type', ['expense', 'udhar_given'])->sum('amount');
        $expensesIn = (float) $this->expenses()->where('type', 'udhar_received')->sum('amount');
        $netExpenses = $expensesOut - $expensesIn;
        $transfersOut = (float) $this->transfersOut()->sum('amount');
        $transfersIn = (float) $this->transfersIn()->sum('amount');

        return (float) $this->opening_balance
            + $fundsIn - $fundsOut
            - $applied + $returned + $salesIn
            - $netExpenses
            - $payoutsOut
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
     * Total account worth in bank = Liquid available balance + Amount blocked in pending IPOs.
     */
    public function getTotalBalanceAttribute(): float
    {
        return (float) $this->current_balance + (float) $this->blocked_in_ipos;
    }

    /**
     * Amount currently invested in allotted shares awaiting sale from this account.
     */
    public function getInvestedInIposAttribute(): float
    {
        return (float) $this->ipoApplications()->whereIn('status', ['allotted', 'partial_allotted'])->sum('amount');
    }

    /**
     * A chronological, running-balance ledger of everything that has ever
     * touched this account — a "passbook" view. Each entry: date, description,
     * debit, credit, balance (after the entry).
     */
    public function buildLedger(): Collection
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

        foreach ($this->ipoApplications()->with(['ipo', 'applicant', 'sales'])->get() as $app) {
            $label = ($app->ipo->name ?? 'IPO').' ('.($app->applicant->name ?? 'applicant').')';

            $entries->push([
                'date' => $app->applied_date,
                'created_at' => $app->created_at,
                'description' => 'Applied: '.$label,
                'debit' => (float) $app->amount,
                'credit' => 0.0,
            ]);

            $isRefunded = ($app->status === 'refunded');
            $hasReturn = ($app->return_amount !== null || $isRefunded);

            // Same rule as current_balance: any recorded return counts, dated or not.
            if ($hasReturn) {
                $returnAmt = $app->return_amount !== null ? (float) $app->return_amount : (float) $app->amount;
                $returnDt = $app->return_date ?? $app->updated_at?->toDateString() ?? $app->applied_date;

                $description = match ($app->status) {
                    'sold' => $app->sales->isNotEmpty() ? 'Partial Refund: '.$label : 'Sale proceeds: '.$label,
                    'refunded' => 'Refund (Mandate Released): '.$label,
                    'partial_allotted' => 'Partial Refund: '.$label,
                    default => 'Refund: '.$label,
                };

                $entries->push([
                    'date' => $returnDt,
                    'created_at' => $app->updated_at ?? $app->created_at,
                    'description' => $description,
                    'debit' => 0.0,
                    'credit' => $returnAmt,
                ]);
            }

            foreach ($app->sales as $sale) {
                $entries->push([
                    'date' => $sale->sale_date,
                    'created_at' => $sale->created_at,
                    'description' => 'Sold '.$sale->shares_sold.' share(s) @ ₹'.number_format((float) $sale->price_per_share, 2)
                        .((float) $sale->charges > 0 ? ' (charges ₹'.number_format((float) $sale->charges, 2).')' : '')
                        .': '.$label,
                    'debit' => 0.0,
                    'credit' => (float) $sale->net_amount,
                ]);
            }
        }

        foreach ($this->profitPayouts()->with(['source', 'applicant'])->get() as $payout) {
            $entries->push([
                'date' => $payout->payout_date,
                'created_at' => $payout->created_at,
                'description' => 'Profit share paid to '.$payout->payee_name,
                'debit' => (float) $payout->amount,
                'credit' => 0.0,
            ]);
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
