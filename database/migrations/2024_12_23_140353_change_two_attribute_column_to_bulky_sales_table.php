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
            $table->string('name_product_bulky_sale')->nullable()->change();
            $table->decimal('old_price_bulky_sale', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->string('name_product_bulky_sale')->change();
            $table->decimal('old_price_bulky_sale', 15, 2)->change();
        });
    }
};
