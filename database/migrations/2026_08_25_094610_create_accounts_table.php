<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "My Bank", "Mummy's Bank", "Cash"
            $table->string('holder_name')->nullable(); // e.g. Mummy, Papa, Nirbhay, Self
            $table->enum('type', ['bank', 'cash'])->default('bank');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
