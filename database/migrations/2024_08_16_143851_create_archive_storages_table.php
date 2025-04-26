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
        Schema::create('archive_storages', function (Blueprint $table) {
            $table->id();
            $table->string('category_product');
            $table->bigInteger('total_category');
            $table->decimal('value_product', 15, 2);
            $table->string('month');
            $table->string('year');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_storages');
    }
};
