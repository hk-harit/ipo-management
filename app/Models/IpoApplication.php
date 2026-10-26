<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\IpoApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class IpoApplication extends Model
{
    /** @use HasFactory<IpoApplicationFactory> */
    use Auditable, BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'ipo_id',
        'applicant_id',
        'account_id',
        'sub_account_id',
        'funding_type',
        'funding_account_id',
        'auto_transfer_id',
        'source_id',
        'amount',
        'applied_date',
        'status',
        'shares_allotted',
        'shares_sold',
        'sale_price_per_share',
        'return_amount',
        'return_date',
        'sold_date',
        'source_share_percent_override',
        'applicant_commission_percent',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'return_amount' => 'decimal:2',
            'sale_price_per_share' => 'decimal:2',
            'source_share_percent_override' => 'decimal:2',
            'applicant_commission_percent' => 'decimal:2',
            'applied_date' => 'date',
            'return_date' => 'date',
            'sold_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $app) {
            // The Sales tab changes status/shares_sold behind an open edit form's back;
            // never let a stale form overwrite what the sales rows say.
            if ($app->exists && $app->sales()->exists()) {
                $app->deriveFromSales($app->sales()->get());
            }

            if ($app->status === 'refunded') {
                if ($app->return_amount === null || (float) $app->return_amount <= 0) {
                    $app->return_amount = $app->amount;
                }
                if (! $app->return_date) {
                    $app->return_date = now()->toDateString();
                }
                $app->shares_allotted = 0;
            }

            // Auto-create AccountTransfer if funded from another account
            if ($app->funding_type === 'transferred' && $app->funding_account_id && $app->funding_account_id != $app->account_id) {
                if (! $app->auto_transfer_id) {
                    $transfer = AccountTransfer::create([
                        'user_id' => $app->user_id,
                        'from_account_id' => $app->funding_account_id,
                        'to_account_id' => $app->account_id,
                        'amount' => $app->amount,
                        'transfer_date' => $app->applied_date ?? now()->toDateString(),
                        'notes' => 'Auto-transfer for IPO application #'.($app->id ?? 'new'),
                    ]);
                    $app->auto_transfer_id = $transfer->id;
                } else {
                    $transfer = AccountTransfer::find($app->auto_transfer_id);
                    if ($transfer) {
                        $transfer->update([
                            'from_account_id' => $app->funding_account_id,
                            'to_account_id' => $app->account_id,
                            'amount' => $app->amount,
                            'transfer_date' => $app->applied_date ?? now()->toDateString(),
                        ]);
                    }
                }
            } elseif ($app->funding_type === 'direct' && $app->auto_transfer_id) {
                AccountTransfer::where('id', $app->auto_transfer_id)->delete();
                $app->auto_transfer_id = null;
                $app->funding_account_id = null;
            }
        });

        static::deleted(function (self $app) {
            if ($app->auto_transfer_id) {
                AccountTransfer::where('id', $app->auto_transfer_id)->delete();
            }
        });
    }

    public function ipo(): BelongsTo
    {
        return $this->belongsTo(Ipo::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sub_account_id');
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'funding_account_id');
    }

    public function autoTransfer(): BelongsTo
    {
        return $this->belongsTo(AccountTransfer::class, 'auto_transfer_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ApplicationSale::class, 'ipo_application_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Profit (or loss) on this application. Only meaningful once shares were
     * actually sold — that's the only point real proceeds vs. cost are both known.
     */
    public function getProfitAttribute(): ?float
    {
        if ($this->status !== 'sold') {
            return null;
        }

        // Old-style single sale: no sales rows, return_amount holds the proceeds.
        if ($this->return_amount === null && $this->sales_count_cached() === 0) {
            return null;
        }

        return round($this->total_received - (float) $this->amount, 2);
    }

    /** Net proceeds from every sale (after charges). */
    public function getSalesNetAttribute(): float
    {
        return (float) ($this->relationLoaded('sales')
            ? $this->sales->sum('net_amount')
            : $this->sales()->sum('net_amount'));
    }

    /** Charges (brokerage, DP, STT...) paid across every sale. */
    public function getSalesChargesAttribute(): float
    {
        return (float) ($this->relationLoaded('sales')
            ? $this->sales->sum('charges')
            : $this->sales()->sum('charges'));
    }

    /**
     * Everything that has come back for this application: the refund (or, for an
     * old-style sale, the proceeds) in return_amount, plus net sale proceeds.
     */
    public function getTotalReceivedAttribute(): float
    {
        return (float) ($this->return_amount ?? 0) + $this->sales_net;
    }

    /** Allotted shares not yet sold. */
    public function getSharesRemainingAttribute(): int
    {
        return max(0, (int) $this->shares_allotted - (int) $this->shares_sold);
    }

    private function sales_count_cached(): int
    {
        return $this->relationLoaded('sales') ? $this->sales->count() : $this->sales()->count();
    }

    /**
     * Re-derives shares_sold, the average price and the status from the sales
     * rows. Sold once every allotted share is gone; if a sale is later edited or
     * deleted so shares are left again, it goes back to allotted / partly allotted.
     */
    public function syncFromSales(): void
    {
        $this->deriveFromSales($this->sales()->get());

        $this->saveQuietly();
        $this->unsetRelation('sales');
    }

    /** Sets shares_sold, average price, status and sold_date from the given sales (no save). */
    private function deriveFromSales(Collection $sales): void
    {
        $sold = (int) $sales->sum('shares_sold');
        $allotted = (int) $this->shares_allotted;

        $this->shares_sold = $sold > 0 ? $sold : null;
        $this->sale_price_per_share = $sold > 0 ? round($sales->sum('gross_amount') / $sold, 2) : null;

        if ($sold > 0 && $allotted > 0 && $sold >= $allotted) {
            $this->status = 'sold';
            $this->sold_date = $sales->max('sale_date');
        } elseif ($this->status === 'sold' && $allotted > 0) {
            $this->status = (float) ($this->return_amount ?? 0) > 0 ? 'partial_allotted' : 'allotted';
            $this->sold_date = null;
        }
    }

    /**
     * What a "not allotted" application failed to get back: the applied amount
     * minus the refund, e.g. a ₹95 deduction on a ₹15,000 mandate. Null when the
     * refund was in full (or the application is not a plain refund).
     */
    public function getRefundShortfallAttribute(): ?float
    {
        if ($this->status !== 'refunded' || $this->return_amount === null) {
            return null;
        }

        $short = round((float) $this->amount - (float) $this->return_amount, 2);

        return $short > 0 ? $short : null;
    }

    /**
     * The % of profit the source gets on THIS application. Every application can
     * override its source's default — the same source might get 50% on one IPO
     * and 30% on another. Falls back to the source's default when not overridden.
     */
    public function getEffectiveSourceSharePercentAttribute(): float
    {
        if ($this->source_share_percent_override !== null) {
            return (float) $this->source_share_percent_override;
        }

        return (float) ($this->source?->profit_share_percent ?? 0);
    }

    /** The funding source's cut of the profit. */
    public function getSourceProfitShareAttribute(): ?float
    {
        if ($this->profit === null || ! $this->source) {
            return null;
        }

        return round($this->profit * ($this->effective_source_share_percent / 100), 2);
    }

    /** The applicant's (friend/cousin's) commission cut of the profit — 0 unless set. */
    public function getApplicantProfitShareAttribute(): ?float
    {
        if ($this->profit === null) {
            return null;
        }

        return round($this->profit * ((float) $this->applicant_commission_percent / 100), 2);
    }

    /** What's left for you after the source's cut and the applicant's commission. */
    public function getYourProfitShareAttribute(): ?float
    {
        if ($this->profit === null) {
            return null;
        }

        return round($this->profit - ($this->source_profit_share ?? 0) - ($this->applicant_profit_share ?? 0), 2);
    }
}
