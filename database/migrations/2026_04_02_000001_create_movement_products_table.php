<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('product_id');
            $table->boolean('is_sku');
            $table->enum('type', ['In', 'Out', 'Move', 'Bundler', 'Bundled', 'Unbundler', 'Unbundled']);
            $table->enum('type_out', ['reguler_sales', 'cargo', 'scrap', 'qcd', 'transfer'])->nullable();
            $table->string('from');
            $table->string('to');
            $table->integer('qty')->nullable();
            $table->dateTime('dateTime');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_products');
    }
};
