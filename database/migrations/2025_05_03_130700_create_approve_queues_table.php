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
        Schema::create('approve_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('product_id')->nullable();
            $table->string('type')->nullable();
            $table->string('code_document')->nullable();
            $table->decimal('old_price_product', 15, 2)->nullable();
            $table->string('new_name_product')->nullable();
            $table->integer('new_quantity_product')->nullable();
            $table->decimal('new_price_product', 15, 2)->nullable();
            $table->decimal('new_discount', 15, 2)->nullable();
            $table->string('new_tag_product')->nullable();
            $table->string('new_category_product')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Menambahkan kolom deleted_at

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approve_queues');
    }
};
