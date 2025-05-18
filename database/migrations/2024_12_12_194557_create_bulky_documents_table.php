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
        Schema::create('bulky_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_bulky');
            $table->bigInteger('total_product_bulky');
            $table->decimal('total_old_price_bulky', 15, 2);
            $table->tinyInteger('discount_bulky');
            $table->decimal('after_price_bulky', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulky_documents');
    }
};
