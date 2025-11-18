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
        Schema::create('summary_inbounds', function (Blueprint $table) {
            $table->id();
            $table->integer('qty');
            $table->decimal('new_price_product', 15, 2);
            $table->decimal('old_price_product', 15, 2);
            $table->decimal('display_price', 15, 2);
            $table->date('inbound_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summary_inbounds');
    }
};
