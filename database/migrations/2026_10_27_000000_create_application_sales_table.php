<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per sale of shares from an application. An application can be
        // sold in several lots on different days, each with its own price and
        // charges (brokerage, DP, STT...). net_amount is what actually lands in the
        // account; the application's profit is the sum of these minus its cost.
        if (! Schema::hasTable('application_sales')) {
            Schema::create('application_sales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ipo_application_id')->constrained()->cascadeOnDelete();
                $table->date('sale_date');
                $table->unsignedInteger('shares_sold');
                $table->decimal('price_per_share', 10, 2);
                $table->decimal('gross_amount', 14, 2);
                $table->decimal('charges', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('ipo_applications', 'sold_date')) {
            Schema::table('ipo_applications', function (Blueprint $table) {
                // The day the last share was sold (set when sales cover every allotted share).
                $table->date('sold_date')->nullable()->after('return_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ipo_applications', 'sold_date')) {
            Schema::table('ipo_applications', function (Blueprint $table) {
                $table->dropColumn('sold_date');
            });
        }

        Schema::dropIfExists('application_sales');
    }
};
