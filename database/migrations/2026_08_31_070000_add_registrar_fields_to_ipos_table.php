<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            // Which registrar's public allotment-status tool this IPO is checked
            // against, and that registrar's own internal id for this issue (visible
            // on their status-check page). Both null until set by hand — auto
            // allotment checking is opt-in per IPO.
            $table->string('registrar')->nullable()->after('status');
            $table->string('registrar_client_id')->nullable()->after('registrar');
        });
    }

    public function down(): void
    {
        Schema::table('ipos', function (Blueprint $table) {
            $table->dropColumn(['registrar', 'registrar_client_id']);
        });
    }
};
