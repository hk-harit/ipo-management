<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            // Sale detail (separate from shares_allotted, since not every allotted share
            // is necessarily sold at once, and the per-share price is worth keeping).
            $table->unsignedInteger('shares_sold')->nullable()->after('shares_allotted');
            $table->decimal('sale_price_per_share', 10, 2)->nullable()->after('shares_sold');

            // Profit-share percentages depend on the application, not just a fixed
            // default — same source can get 50% on one IPO and 30% on another.
            // Null override = fall back to the source's default profit_share_percent.
            $table->decimal('source_share_percent_override', 5, 2)->nullable()->after('return_date');

            // Separate, optional commission for the applicant (friend/cousin) themself —
            // most applications pay them 0%, but sometimes they get a cut too.
            $table->decimal('applicant_commission_percent', 5, 2)->default(0)->after('source_share_percent_override');
        });
    }

    public function down(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->dropColumn([
                'shares_sold',
                'sale_price_per_share',
                'source_share_percent_override',
                'applicant_commission_percent',
            ]);
        });
    }
};
