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
          Schema::table('bulky_sales', function (Blueprint $table) {
            $table->decimal('actual_old_price_product', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropColumn(['actual_old_price_product']);
        });
    }
};
