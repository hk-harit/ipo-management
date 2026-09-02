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
