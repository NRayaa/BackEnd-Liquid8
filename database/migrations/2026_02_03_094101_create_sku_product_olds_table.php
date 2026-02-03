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
        Schema::create('sku_product_olds', function (Blueprint $table) {
            $table->id(); 
            $table->string('code_document');
            $table->string('old_barcode_product');
            $table->string('old_name_product',1024);
            $table->decimal('old_price_product', 12, 2); 
            $table->integer('old_quantity_product')->default(0);
            $table->integer('actual_quantity_product')->default(0);
            $table->integer('damaged_quantity_product')->default(0);
            $table->integer('lost_quantity_product')->default(0);
    
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_product_olds');
    }
};
