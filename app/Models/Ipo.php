<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ipo extends Model
{
    /** @use HasFactory<\Database\Factories\IpoFactory> */
    use HasFactory, BelongsToUser;

    protected $fillable = [
        'user_id',
        'external_id',
        'external_source',
        'external_slug',
        'external_ids',
        'name',
        'symbol',
        'issue_type',
        'open_date',
        'close_date',
        'allotment_date',
        'listing_date',
        'price_per_share',
        'price_min',
        'price_max',
        'gmp',
        'gmp_percent',
        'gmp_updated_at',
        'lot_size',
        'min_amount',
        'issue_size',
        'subscription_retail',
        'subscription_total',
        'subscription_qib',
        'subscription_nii',
        'subscription_applications',
        'subscription_updated_at',
        'logo_url',
        'info_url',
        'prospectus_url',
        'about',
        'synced_at',
        'status',
        'registrar',
        'registrar_client_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'open_date' => 'date',
            'close_date' => 'date',
            'allotment_date' => 'date',
            'listing_date' => 'date',
            'external_ids' => 'array',
            'synced_at' => 'datetime',
            'gmp_updated_at' => 'datetime',
            'subscription_updated_at' => 'datetime',
            'price_per_share' => 'decimal:2',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'gmp' => 'decimal:2',
            'gmp_percent' => 'decimal:2',
            'subscription_retail' => 'decimal:2',
            'subscription_total' => 'decimal:2',
            'subscription_qib' => 'decimal:2',
            'subscription_nii' => 'decimal:2',
        ];
    }

    /** Mainboard issues, as opposed to the SME board. */
    public function scopeMainboard(Builder $query): Builder
    {
        return $query->where('issue_type', 'EQ');
    }

    public function scopeSme(Builder $query): Builder
    {
        return $query->where('issue_type', 'SME');
    }

    /** Worth looking at right now — open for bids, or about to be. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'upcoming']);
    }

    /**
     * Most urgent first: open issues (closing soonest first — those need a
     * decision today), then upcoming (opening soonest first), then everything
     * already decided, most recent first. Shared by the IPO list's default
     * sort and the apply-form picker so "what needs attention" always means
     * the same order everywhere.
     *
     * Plain CASE/WHEN rather than MySQL's FIELD(), so this works on any driver.
     */
    public function scopeOrderByUrgency(Builder $query): Builder
    {
        return $query
            ->orderByRaw("
                CASE status
                    WHEN 'open' THEN 0
                    WHEN 'upcoming' THEN 1
                    WHEN 'closed' THEN 2
                    WHEN 'allotted' THEN 3
                    WHEN 'listed' THEN 4
                    ELSE 5
                END
            ")
            ->orderByRaw("
                CASE
                    WHEN status = 'open' THEN close_date
                    WHEN status = 'upcoming' THEN open_date
                END ASC
            ")
            ->orderByDesc('open_date');
    }

    public function isMainboard(): bool
    {
        return $this->issue_type === 'EQ';
    }

    /**
     * The expected listing price implied by the grey market — issue price plus
     * the premium. A hint, not a promise: GMP is an unregulated quote.
     */
    public function getEstimatedListingPriceAttribute(): ?float
    {
        if ($this->gmp === null || $this->price_per_share === null) {
            return null;
        }

        return (float) $this->price_per_share + (float) $this->gmp;
    }

    /** What one lot would gain at the grey market price, before any costs. */
    public function getEstimatedGainPerLotAttribute(): ?float
    {
        if ($this->gmp === null || ! $this->lot_size) {
            return null;
        }

        return (float) $this->gmp * $this->lot_size;
    }

    /** True once retail has bid for more than the shares on offer. */
    public function isRetailOversubscribed(): bool
    {
        return $this->subscription_retail !== null && (float) $this->subscription_retail >= 1;
    }

    /** True when this row came from ipoalerts.in rather than being typed in by hand. */
    public function isSynced(): bool
    {
        return filled($this->external_id);
    }

    /** True once a registrar + its per-issue id are set, so "Check Allotment" has somewhere to ask. */
    public function canAutoCheckAllotment(): bool
    {
        return filled($this->registrar) && filled($this->registrar_client_id);
    }

    /** "₹190 – ₹200", or a single price when the band has no spread. */
    public function getPriceBandAttribute(): ?string
    {
        if ($this->price_min === null && $this->price_max === null) {
            return $this->price_per_share ? '₹'.number_format((float) $this->price_per_share, 2) : null;
        }

        $min = (float) ($this->price_min ?? $this->price_max);
        $max = (float) ($this->price_max ?? $this->price_min);

        return $min === $max
            ? '₹'.number_format($max, 2)
            : '₹'.number_format($min, 2).' – ₹'.number_format($max, 2);
    }

    /** What one lot costs at the top of the band — the usual retail application amount. */
    public function getLotAmountAttribute(): ?float
    {
        if ($this->min_amount !== null) {
            return (float) $this->min_amount;
        }

        if ($this->lot_size && $this->price_per_share) {
            return (float) $this->lot_size * (float) $this->price_per_share;
        }

        return null;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    public function getTotalAppliedAttribute(): float
    {
        return (float) $this->applications()->sum('amount');
    }

    public function getTotalReturnedAttribute(): float
    {
        return (float) $this->applications()->sum('return_amount');
    }
}
