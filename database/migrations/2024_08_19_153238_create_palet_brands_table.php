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
        Schema::create('palet_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('palet_id');
            $table->foreignId('brand_id');
            $table->string('palet_brand_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('palet_brands');
    }
};