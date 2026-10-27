<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use Auditable, BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'type',
        'category',
        'person_name',
        'status',
        'amount',
        'expense_date',
        'due_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeRegularExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeUdharGiven($query)
    {
        return $query->where('type', 'udhar_given');
    }

    public function scopeUdharReceived($query)
    {
        return $query->where('type', 'udhar_received');
    }

    public function scopePendingUdhar($query)
    {
        return $query->where('type', 'udhar_given')->where('status', 'pending');
    }

    public function getDisplayDescriptionAttribute(): string
    {
        return match ($this->type) {
            'udhar_given' => 'Loan Given to '.($this->person_name ?: 'Person'),
            'udhar_received' => 'Loan Received from '.($this->person_name ?: 'Person'),
            default => 'Expense: '.($this->category ?: 'General'),
        };
    }
}
