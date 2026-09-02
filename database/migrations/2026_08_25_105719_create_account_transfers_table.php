<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money moved between two of your own tracked accounts — e.g. you fund an
        // application from a friend's account, but their bank has an issue, so you
        // move the money there from your own bank first. Both ends are Accounts;
        // this is separate from fund_transactions (which involve an outside Source).
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
