<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('rank')->unique();
            $table->integer('min_transactions')->default(0);
            $table->decimal('min_amount_transaction', 15, 2)->default(0.00);
            $table->decimal('percentage_discount', 5, 2)->default(0.00);
            $table->integer('expired_weeks')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_ranks');
    }
};
