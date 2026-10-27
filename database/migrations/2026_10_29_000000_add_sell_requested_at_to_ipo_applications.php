<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sell order is placed first (about 9:00 - 9:30, before the market opens) and only
        // executes later, so "request sent" is a stage of its own between allotted and sold.
        if (! Schema::hasColumn('ipo_applications', 'sell_requested_at')) {
            Schema::table('ipo_applications', function (Blueprint $table) {
                $table->timestamp('sell_requested_at')->nullable()->after('sold_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->dropColumn('sell_requested_at');
        });
    }
};
