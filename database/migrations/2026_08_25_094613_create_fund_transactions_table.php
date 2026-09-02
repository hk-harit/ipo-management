<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Records money moving between a Source (Uncle A, Brother B, ...) and one of your Accounts.
        // type = given    -> source hands you money, it lands in the account (account +, source due +)
        // type = returned -> you pay the source back out of the account (account -, source due -)
        Schema::create('fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['given', 'returned']);
            $table->decimal('amount', 14, 2);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transactions');
    }
};
