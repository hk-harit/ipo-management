<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'sources',
        'accounts',
        'applicants',
        'ipos',
        'ipo_applications',
        'fund_transactions',
        'expenses',
        'account_transfers',
    ];

    public function up(): void
    {
        // Every row belongs to exactly one login — that's the whole tenancy model here.
        // Each user's Sources/Accounts/IPOs/etc. are completely separate from anyone else's.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        // Backfill: all data that already exists belongs to whoever logged in first.
        $ownerId = DB::table('users')->orderBy('id')->value('id');

        if ($ownerId) {
            foreach ($this->tables as $table) {
                DB::table($table)->whereNull('user_id')->update(['user_id' => $ownerId]);
            }
        }

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('user_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('user_id');
            });
        }
    }
};
