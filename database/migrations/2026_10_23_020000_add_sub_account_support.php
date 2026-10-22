<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')->constrained('accounts')->nullOnDelete();
            $table->boolean('is_sub_account')->default(false)->after('parent_id');
        });

        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->foreignId('sub_account_id')->nullable()->after('account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->dropForeign(['sub_account_id']);
            $table->dropColumn(['sub_account_id']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'is_sub_account']);
        });
    }
};
