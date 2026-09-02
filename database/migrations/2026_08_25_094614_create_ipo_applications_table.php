<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One IPO application: applied in an Applicant's name, funded from one of your Accounts.
        // On create: account is debited by `amount`.
        // When the result is known: `return_amount` / `return_date` record whatever comes back to
        // the account (a full/partial refund if not allotted, or eventual sale proceeds later).
        Schema::create('ipo_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ipo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('applied_date');
            $table->enum('status', ['applied', 'refunded', 'allotted', 'partial_allotted', 'sold'])->default('applied');
            $table->unsignedInteger('shares_allotted')->nullable();
            $table->decimal('return_amount', 14, 2)->nullable();
            $table->date('return_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipo_applications');
    }
};
