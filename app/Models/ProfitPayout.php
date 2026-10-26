<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Profit-share money paid out to a Source or an Applicant, from one of your accounts.
 * Exactly one of source_id / applicant_id is set.
 */
class ProfitPayout extends Model
{
    use Auditable, BelongsToUser;

    protected $fillable = [
        'user_id',
        'source_id',
        'applicant_id',
        'account_id',
        'amount',
        'payout_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payout_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $payout) {
            if (filled($payout->source_id) === filled($payout->applicant_id)) {
                throw ValidationException::withMessages([
                    'source_id' => 'Choose either a source or an applicant to pay — not both, not neither.',
                ]);
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getPayeeNameAttribute(): string
    {
        return $this->source?->name ?? $this->applicant?->name ?? 'Payee';
    }

    public function getPayeeTypeAttribute(): string
    {
        return filled($this->source_id) ? 'source' : 'applicant';
    }
}
