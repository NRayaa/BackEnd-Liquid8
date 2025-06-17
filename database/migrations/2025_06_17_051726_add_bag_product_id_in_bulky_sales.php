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
            $table->foreignId('bag_product_id')
                ->nullable()
                ->constrained('bag_products')
                ->onDelete('cascade')
                ->after('bulky_document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropForeign(['bag_product_id']);
            $table->dropColumn('bag_product_id');
        });
    }
};
