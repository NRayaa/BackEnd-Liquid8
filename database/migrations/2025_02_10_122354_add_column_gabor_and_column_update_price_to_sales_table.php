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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('gabor_sale', 15, 2)->nullable()->after('product_price_sale');
            $table->decimal('product_update_price_sale', 15, 2)->nullable()->after('gabor_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('gabor_sale');
            $table->dropColumn('product_update_price_sale');
        });
    }
};
