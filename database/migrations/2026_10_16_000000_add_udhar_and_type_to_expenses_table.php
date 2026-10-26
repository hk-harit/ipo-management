<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded: the live database already has these columns (added before the
        // migration was recorded), so a plain add would fail with "duplicate column".
        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'type')) {
                $table->string('type')->default('expense')->after('account_id');
            }
            if (! Schema::hasColumn('expenses', 'person_name')) {
                $table->string('person_name')->nullable()->after('category');
            }
            if (! Schema::hasColumn('expenses', 'status')) {
                $table->string('status')->default('completed')->after('person_name');
            }
            if (! Schema::hasColumn('expenses', 'due_date')) {
                $table->date('due_date')->nullable()->after('expense_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['type', 'person_name', 'status', 'due_date']);
        });
    }
};
