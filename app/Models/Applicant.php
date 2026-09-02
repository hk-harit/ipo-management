<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    /** @use HasFactory<\Database\Factories\ApplicantFactory> */
    use HasFactory, BelongsToUser;

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

    public function ipoApplications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    /** Total commission owed to this applicant from sold applications, where set. */
    public function getTotalCommissionDueAttribute(): float
    {
        return (float) $this->ipoApplications()
            ->where('status', 'sold')
            ->get()
            ->sum('applicant_profit_share');
    }
}
