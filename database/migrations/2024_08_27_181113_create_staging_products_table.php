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
        Schema::create('staging_products', function (Blueprint $table) {
            $table->id();
            $table->string('code_document')->nullable();
            $table->string('old_barcode_product')->nullable();
            $table->string('new_barcode_product')->unique()->nullable();
            $table->string('new_name_product', 1024)->nullable();
            $table->integer('new_quantity_product')->nullable();
            $table->decimal('new_price_product', 15, 2)->nullable();
            $table->decimal('old_price_product', 15, 2)->nullable();
            $table->date('new_date_in_product')->nullable();
            $table->enum('new_status_product', ['display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving']);
            $table->json('new_quality')->nullable();
            $table->string('new_category_product')->nullable();
            $table->string('new_tag_product')->nullable();
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staging_products');
    }
};
