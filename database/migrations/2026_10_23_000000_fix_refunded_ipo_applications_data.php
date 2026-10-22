<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For any IPO application already marked as refunded without a return amount, set it to the applied amount
        DB::table('ipo_applications')
            ->where('status', 'refunded')
            ->where(function ($query) {
                $query->whereNull('return_amount')
                    ->orWhere('return_amount', 0);
            })
            ->update([
                'return_amount' => DB::raw('amount'),
            ]);

        // For any IPO application already marked as refunded without a return date, default to applied_date or now
        DB::table('ipo_applications')
            ->where('status', 'refunded')
            ->whereNull('return_date')
            ->update([
                'return_date' => DB::raw('COALESCE(applied_date, CURRENT_DATE)'),
            ]);
    }

    public function down(): void
    {
        // No-op
    }
};
