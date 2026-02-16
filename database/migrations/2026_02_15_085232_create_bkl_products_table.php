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
        Schema::create('bkl_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rack_id')->nullable();
            $table->string('code_document')->nullable();
            $table->string('old_barcode_product')->nullable();
            $table->string('new_barcode_product')->nullable();
            $table->string('new_name_product', 1024)->nullable();
            $table->integer('new_quantity_product')->nullable();
            $table->decimal('new_price_product', 15, 2)->nullable();
            $table->decimal('old_price_product', 15, 2)->nullable();
            $table->date('new_date_in_product')->nullable();
            $table->enum('new_status_product', ['display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving', 'scrap_qcd'])->nullable();
            $table->json('new_quality')->nullable();
            $table->string('new_category_product')->nullable();
            $table->string('new_tag_product')->nullable();
            $table->timestamps();
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->decimal('display_price', 15, 2)->default(0); 
            $table->enum('type', ['type1', 'type2'])->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('is_so', ['check', 'done', 'lost', 'addition'])->nullable();
            $table->unsignedBigInteger('user_so')->nullable();
            $table->decimal('actual_old_price_product', 15, 2)->nullable();
            $table->json('actual_new_quality')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bkl_products');
    }
};
