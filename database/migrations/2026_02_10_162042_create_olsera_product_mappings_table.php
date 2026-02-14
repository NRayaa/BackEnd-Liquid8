<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olsera_product_mappings', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('destination_id')
                  ->constrained('destinations')
                  ->onDelete('cascade');
            $table->string('wms_identifier'); 
            $table->string('olsera_id'); 
            $table->enum('type', ['color_tag', 'sku_product'])->default('color_tag');
            
            $table->timestamps();
            $table->unique(['wms_identifier', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olsera_product_mappings');
    }
};