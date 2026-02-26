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
        Schema::create('scrap_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrap_document_id')->constrained('scrap_documents');
            $table->unsignedBigInteger('productable_id');
            $table->string('productable_type');
            $table->index(['productable_id', 'productable_type']);
            $table->unique(['scrap_document_id', 'productable_id', 'productable_type'], 'scrap_item_unique');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrap_document_items');
    }
};
