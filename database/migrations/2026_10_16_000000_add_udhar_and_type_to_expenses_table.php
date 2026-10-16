<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('type')->default('expense')->after('account_id');
            $table->string('person_name')->nullable()->after('category');
            $table->string('status')->default('completed')->after('person_name');
            $table->date('due_date')->nullable()->after('expense_date');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['type', 'person_name', 'status', 'due_date']);
        });
    }
};
