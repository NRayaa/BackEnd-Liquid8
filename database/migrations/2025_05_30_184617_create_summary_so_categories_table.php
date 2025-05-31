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
        Schema::create('summary_so_categories', function (Blueprint $table) {
            $table->id();
            $table->integer('product_bundle')->nullable();
            $table->integer('product_staging')->nullable();
            $table->integer('product_inventory')->nullable();
            $table->integer('product_damaged')->nullable();
            $table->integer('product_abnormal')->nullable();
            $table->integer('product_lost')->nullable();
            $table->integer('product_addition')->nullable();
            $table->enum('type', ['done', 'process'])->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summary_so_categories');
    }
};
