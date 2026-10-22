<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->string('funding_type')->default('direct')->after('account_id'); // 'direct' or 'transferred'
            $table->foreignId('funding_account_id')->nullable()->after('funding_type')->constrained('accounts')->nullOnDelete();
            $table->foreignId('auto_transfer_id')->nullable()->after('funding_account_id')->constrained('account_transfers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ipo_applications', function (Blueprint $table) {
            $table->dropForeign(['auto_transfer_id']);
            $table->dropForeign(['funding_account_id']);
            $table->dropColumn(['funding_type', 'funding_account_id', 'auto_transfer_id']);
        });
    }
};
