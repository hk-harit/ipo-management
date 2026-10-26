<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A lot of shares sold out of an IPO application: when, how many, at what price,
 * and what it cost to sell. The application's status, shares_sold and profit are
 * all derived from its sales (see IpoApplication::syncFromSales).
 */
class ApplicationSale extends Model
{
    use Auditable, BelongsToUser;

    protected $fillable = [
        'user_id',
        'ipo_application_id',
        'sale_date',
        'shares_sold',
        'price_per_share',
        'gross_amount',
        'charges',
        'net_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'price_per_share' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'charges' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $sale) {
            $sale->charges = (float) ($sale->charges ?? 0);
            $sale->gross_amount = round((int) $sale->shares_sold * (float) $sale->price_per_share, 2);
            $sale->net_amount = round($sale->gross_amount - $sale->charges, 2);

            // You cannot sell more than was allotted. (Skipped while shares_allotted
            // is unknown, so a sale can still be recorded before the count is typed in.)
            $application = $sale->application;
            $allotted = (int) $application?->shares_allotted;

            if ($allotted > 0) {
                $others = (int) static::query()
                    ->where('ipo_application_id', $sale->ipo_application_id)
                    ->when($sale->exists, fn ($q) => $q->whereKeyNot($sale->getKey()))
                    ->sum('shares_sold');

                if ($others + (int) $sale->shares_sold > $allotted) {
                    throw ValidationException::withMessages([
                        'shares_sold' => 'Only '.max(0, $allotted - $others).' share(s) left to sell (allotted '.$allotted.').',
                    ]);
                }
            }
        });

        static::saved(fn (self $sale) => $sale->application?->syncFromSales());
        static::deleted(fn (self $sale) => $sale->application?->syncFromSales());
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(IpoApplication::class, 'ipo_application_id');
    }
}
