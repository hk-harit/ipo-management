<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            // chittorgarh.com and investorgain.com share one slug per IPO
            // ("skyways-air-ipo"), which is the only reliable way to tell that
            // their "Skyways Air" and "Skyways Air Services Ltd." are one issue.
            $table->string('external_slug')->nullable()->after('external_source');
            $table->index(['user_id', 'external_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'external_slug']);
            $table->dropColumn('external_slug');
        });
    }
};
