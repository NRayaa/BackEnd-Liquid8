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
        Schema::create('sale_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_sale')->unique();
            $table->string('buyer_name_document_sale');
            $table->bigInteger('total_product_document_sale');
            $table->bigInteger('total_price_document_sale');
            $table->enum('status_document_sale', ['proses', 'selesai']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_documents');
    }
};