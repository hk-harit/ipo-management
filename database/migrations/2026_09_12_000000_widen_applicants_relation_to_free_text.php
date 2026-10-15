<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `relation` started as an enum for the admin's own "who is this person to
     * me" categorisation (friend / cousin / family / other — see
     * ApplicantResource). The investor-facing PAN manager reuses the same
     * column for actual family relations (Self, Father, Mother, Spouse, ...),
     * none of which are in that enum — on MySQL, inserting any of them fails
     * outright (silently accepted as an untyped column on SQLite, which is
     * why the test suite never caught this). Widened to a plain string, the
     * same way `sources.relation` already works, since there's no fixed list
     * that covers both use cases.
     */
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('relation', 50)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->enum('relation', ['friend', 'cousin', 'family', 'other'])->default('friend')->change();
        });
    }
};
