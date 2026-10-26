<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Profit-share money actually paid out to a Source or an Applicant. It leaves
        // one of your accounts, so it reduces that account's balance like an expense.
        // Source / applicant / account are RESTRICT, not cascade: a payout is a
        // payment that really happened and must never vanish with its payee.
        if (Schema::hasTable('profit_payouts')) {
            return;
        }

        Schema::create('profit_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('applicant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payout_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_payouts');
    }
};
