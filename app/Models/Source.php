<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\BlocksDeleteWithHistory;
use Database\Factories\SourceFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Someone who funds IPO applications — an uncle, a brother, a friend.
 *
 * A Source is also a login: given a mobile/email and a password by the admin,
 * they can sign into the read-only `source` panel and see exactly what was
 * applied with their money, what was allotted, and what they are owed.
 */
class Source extends Model implements AuthenticatableContract, FilamentUser
{
    /** @use HasFactory<SourceFactory> */
    use Auditable, AuthenticatableTrait, BelongsToUser, BlocksDeleteWithHistory, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'relation',
        'mobile',
        'email',
        'password',
        'portal_enabled',
        'profit_share_percent',
        'notes',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'portal_enabled' => 'boolean',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'profit_share_percent' => 'decimal:2',
        ];
    }

    /**
     * Sources belong to the `source` panel only, and only once the admin has
     * switched the portal on, given them a password, and kept them active.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'source'
            && $this->portal_enabled
            && $this->is_active
            && filled($this->password);
    }

    /**
     * Mobiles get typed every which way — "+91 98765 43210", "098765-43210".
     * Store and match on the bare 10 digits so any of those forms log in.
     */
    public static function normaliseMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    protected function setMobileAttribute(?string $value): void
    {
        $this->attributes['mobile'] = static::normaliseMobile($value);
    }

    public function deletionBlockedReason(): ?string
    {
        return $this->describeHistory([
            'fund transaction(s)' => $this->fundTransactions()->count(),
            'IPO application(s)' => $this->ipoApplications()->count(),
            'profit payout(s)' => $this->profitPayouts()->count(),
        ]);
    }

    public function profitPayouts(): HasMany
    {
        return $this->hasMany(ProfitPayout::class);
    }

    public function fundTransactions(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    public function ipoApplications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    /** Total profit-share owed to this source from sold applications funded by them. */
    public function getTotalProfitShareDueAttribute(): float
    {
        return (float) $this->ipoApplications()
            ->where('status', 'sold')
            ->with(['sales', 'source'])
            ->get()
            ->sum('source_profit_share');
    }

    /** Profit share paid out to this source so far. */
    public function getProfitSharePaidAttribute(): float
    {
        return (float) $this->profitPayouts()->sum('amount');
    }

    /** Profit share earned but not yet paid out (negative if paid ahead). */
    public function getProfitSharePendingAttribute(): float
    {
        return round($this->total_profit_share_due - $this->profit_share_paid, 2);
    }

    /** Everything you owe this source: their money still with you plus unpaid profit share. */
    public function getTotalPayableAttribute(): float
    {
        return round($this->balance_due + $this->profit_share_pending, 2);
    }

    /** Total money this source has ever handed in. */
    public function getTotalGivenAttribute(): float
    {
        return (float) $this->fundTransactions()->where('type', 'given')->sum('amount');
    }

    /** Total money ever paid back to this source. */
    public function getTotalReturnedAttribute(): float
    {
        return (float) $this->fundTransactions()->where('type', 'returned')->sum('amount');
    }

    /** What you currently owe this source (given - returned). */
    public function getBalanceDueAttribute(): float
    {
        return $this->total_given - $this->total_returned;
    }

    /** Everything ever applied with this source's money, across all IPOs. */
    public function getTotalAppliedAttribute(): float
    {
        return (float) $this->ipoApplications()->sum('amount');
    }

    /** Still applied, result not yet known — their money currently locked up. */
    public function getBlockedInIposAttribute(): float
    {
        return (float) $this->ipoApplications()->whereIn('status', IpoApplication::BLOCKED)->sum('amount');
    }

    /** Applications that got shares — fully or partially. */
    public function getAllottedCountAttribute(): int
    {
        return $this->ipoApplications()
            ->whereIn('status', ['allotted', 'partial_allotted', 'sold'])
            ->count();
    }

    /**
     * IPO-by-IPO summary of everything applied with this source's money: how much
     * went in, how many applications, how many got shares, what came back, and
     * this source's cut of the profit. Newest IPO first.
     */
    public function ipoWiseSummary(): Collection
    {
        return $this->ipoApplications()
            ->with(['ipo', 'applicant', 'sales'])
            ->get()
            ->groupBy('ipo_id')
            ->map(function (Collection $apps) {
                $ipo = $apps->first()->ipo;
                $sold = $apps->where('status', 'sold');

                return [
                    'ipo_id' => $ipo?->id,
                    'ipo' => $ipo?->name ?? 'Unknown IPO',
                    'status' => $ipo?->status,
                    'applied_date' => $apps->min('applied_date'),
                    'applications' => $apps->count(),
                    'applicants' => $apps->map(fn ($a) => $a->applicant?->name)->filter()->unique()->values()->implode(', '),
                    'applied' => (float) $apps->sum('amount'),
                    'allotted_apps' => $apps->whereIn('status', ['allotted', 'partial_allotted', 'sold'])->count(),
                    'shares_allotted' => (int) $apps->sum('shares_allotted'),
                    'returned' => (float) $apps->sum(fn ($a) => $a->total_received),
                    'profit' => (float) $sold->sum('profit'),
                    'my_share' => (float) $sold->sum('source_profit_share'),
                ];
            })
            ->sortByDesc('applied_date')
            ->values();
    }

    /**
     * A running-balance passbook of this source's money: every rupee handed in,
     * every rupee paid back, with the outstanding balance after each entry.
     */
    public function buildLedger(): Collection
    {
        $entries = $this->fundTransactions()
            ->with('account')
            ->get()
            ->map(fn (FundTransaction $tx) => [
                'date' => $tx->transaction_date,
                'created_at' => $tx->created_at,
                'description' => $tx->type === 'given'
                    ? 'You gave — into '.($tx->account->name ?? 'account')
                    : 'Paid back to you — from '.($tx->account->name ?? 'account'),
                'given' => $tx->type === 'given' ? (float) $tx->amount : 0.0,
                'returned' => $tx->type === 'returned' ? (float) $tx->amount : 0.0,
                'notes' => $tx->notes,
            ])
            ->sortBy([
                fn ($a, $b) => $a['date'] <=> $b['date'],
                fn ($a, $b) => $a['created_at'] <=> $b['created_at'],
            ])
            ->values();

        $balance = 0.0;

        return $entries->map(function (array $entry) use (&$balance) {
            $balance += $entry['given'] - $entry['returned'];
            $entry['balance'] = $balance;

            return $entry;
        });
    }

    /**
     * The accounts this source's money actually went into, with how much of it
     * came from them and what the account holds today.
     */
    public function fundedAccounts(): Collection
    {
        $accountIds = $this->fundTransactions()->distinct()->pluck('account_id')
            ->merge($this->ipoApplications()->distinct()->pluck('account_id'))
            ->filter()
            ->unique();

        return Account::whereIn('id', $accountIds)->orderBy('name')->get()
            ->map(fn (Account $account) => [
                'account' => $account->name,
                'holder' => $account->holder_name,
                'type' => $account->type,
                'you_gave' => (float) $this->fundTransactions()
                    ->where('account_id', $account->id)->where('type', 'given')->sum('amount'),
                'paid_back' => (float) $this->fundTransactions()
                    ->where('account_id', $account->id)->where('type', 'returned')->sum('amount'),
                'applied_from' => (float) $this->ipoApplications()
                    ->where('account_id', $account->id)->sum('amount'),
            ]);
    }
}
