<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpoApplication extends Model
{
    /** @use HasFactory<\Database\Factories\IpoApplicationFactory> */
    use HasFactory, BelongsToUser;

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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $app) {
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
                        'notes' => 'Auto-transfer for IPO application #' . ($app->id ?? 'new'),
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
        if ($this->status !== 'sold' || $this->return_amount === null) {
            return null;
        }

        return (float) $this->return_amount - (float) $this->amount;
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
