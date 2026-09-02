<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            // ipoalerts.in's own id for this IPO. It's how a synced row is found
            // again on the next run, so an IPO is updated rather than duplicated.
            // Scoped per user because every admin holds their own copy of the list.
            $table->string('external_id')->nullable()->after('user_id');
            $table->string('external_source')->nullable()->after('external_id');

            $table->string('symbol')->nullable()->after('name');
            // "EQ" (mainboard) or "SME" — worth keeping, the two behave differently.
            $table->string('issue_type', 10)->nullable()->after('symbol');

            // priceRange comes as a band ("190-200"); price_per_share keeps the
            // upper end so existing calculations carry on working unchanged.
            $table->decimal('price_min', 10, 2)->nullable()->after('price_per_share');
            $table->decimal('price_max', 10, 2)->nullable()->after('price_min');
            $table->decimal('min_amount', 14, 2)->nullable()->after('lot_size');

            $table->string('issue_size')->nullable()->after('min_amount');
            $table->string('logo_url', 512)->nullable()->after('issue_size');
            $table->string('info_url', 512)->nullable()->after('logo_url');
            $table->string('prospectus_url', 512)->nullable()->after('info_url');
            $table->text('about')->nullable()->after('prospectus_url');

            $table->timestamp('synced_at')->nullable()->after('about');

            $table->unique(['user_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_id']);
            $table->dropColumn([
                'external_id', 'external_source', 'symbol', 'issue_type',
                'price_min', 'price_max', 'min_amount', 'issue_size',
                'logo_url', 'info_url', 'prospectus_url', 'about', 'synced_at',
            ]);
        });
    }
};
