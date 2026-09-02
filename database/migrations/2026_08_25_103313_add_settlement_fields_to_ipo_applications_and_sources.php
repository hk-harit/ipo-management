<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // What % of profit from a sold application funded by this source goes to the source.
            // The rest is yours. Default 50/50, editable per source.
            $table->decimal('profit_share_percent', 5, 2)->default(50)->after('relation');
        });

        Schema::table('ipo_applications', function (Blueprint $table) {
            // Which source's money effectively funded this specific application, so profit
            // on sale can be split with them. Nullable: not every application needs to be
            // traced to one source (e.g. self-funded from your own account).
            $table->foreignId('source_id')->nullable()->after('account_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('profit_share_percent');
        });
    }
};
