<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            // Every feed id that has ever described this IPO, e.g.
            // ["chittorgarh:2622", "investorgain:1897"]. `external_id` is only
            // the first one claimed; this records the rest, which is what tells
            // us two differently-named rows are one issue.
            $table->json('external_ids')->nullable()->after('external_slug');
        });
    }

    public function down(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            $table->dropColumn('external_ids');
        });
    }
};
