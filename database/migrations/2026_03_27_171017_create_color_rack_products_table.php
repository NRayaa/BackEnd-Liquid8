<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('color_rack_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('color_rack_id')->constrained('color_racks')->onDelete('cascade');
            $table->foreignId('new_product_id')->nullable()->constrained('new_products')->onDelete('cascade');
            $table->unsignedBigInteger('bundle_id')->nullable();
            $table->foreign('bundle_id')->references('id')->on('bundles')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('color_rack_products');
    }
};
