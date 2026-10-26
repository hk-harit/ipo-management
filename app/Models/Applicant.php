<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\BlocksDeleteWithHistory;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use BelongsToUser, BlocksDeleteWithHistory, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'relation',
        'pan_number',
        'demat_account',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function deletionBlockedReason(): ?string
    {
        return $this->describeHistory([
            'IPO application(s)' => $this->ipoApplications()->count(),
            'commission payout(s)' => $this->profitPayouts()->count(),
        ]);
    }

    public function profitPayouts(): HasMany
    {
        return $this->hasMany(ProfitPayout::class);
    }

    public function ipoApplications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    /** Total commission owed to this applicant from sold applications, where set. */
    public function getTotalCommissionDueAttribute(): float
    {
        return (float) $this->ipoApplications()
            ->where('status', 'sold')
            ->with(['sales', 'source'])
            ->get()
            ->sum('applicant_profit_share');
    }

    /** Commission paid out to this applicant so far. */
    public function getCommissionPaidAttribute(): float
    {
        return (float) $this->profitPayouts()->sum('amount');
    }

    /** Commission earned but not yet paid out. */
    public function getCommissionPendingAttribute(): float
    {
        return round($this->total_commission_due - $this->commission_paid, 2);
    }
}
