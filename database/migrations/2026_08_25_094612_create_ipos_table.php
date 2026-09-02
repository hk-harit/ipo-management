<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('open_date')->nullable();
            $table->date('close_date')->nullable();
            $table->date('allotment_date')->nullable();
            $table->date('listing_date')->nullable();
            $table->decimal('price_per_share', 10, 2)->nullable();
            $table->unsignedInteger('lot_size')->nullable();
            $table->enum('status', ['upcoming', 'open', 'closed', 'allotted', 'listed'])->default('upcoming');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipos');
    }
};
