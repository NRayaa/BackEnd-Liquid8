<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('history_bundlings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('code_document')->nullable();
            $table->string('barcode_product');
            $table->string('name_product');
            $table->decimal('price_before', 12, 2)->default(0); 
            $table->decimal('price_after', 12, 2)->default(0);  
            $table->integer('qty_before')->default(0); 
            $table->integer('qty_after')->default(0); 
            $table->integer('total_qty_bundle')->nullable(); 
            $table->string('type'); 
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('history_bundlings');
    }
};