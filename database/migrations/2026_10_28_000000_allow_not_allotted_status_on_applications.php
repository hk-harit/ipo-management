<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // status was an ENUM of five values. An application now also passes through
        // 'not_allotted' (no shares; the refund has not arrived yet) before 'refunded'
        // (the money is back), so it becomes a plain string and the app owns the list.
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->string('status', 30)->default('applied')->change();
        });
    }

    public function down(): void
    {
        // Applications sitting in 'not_allotted' have no equivalent in the old list.
        DB::table('ipo_applications')->where('status', 'not_allotted')->update(['status' => 'applied']);

        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->enum('status', ['applied', 'refunded', 'allotted', 'partial_allotted', 'sold'])->default('applied')->change();
        });
    }
};
