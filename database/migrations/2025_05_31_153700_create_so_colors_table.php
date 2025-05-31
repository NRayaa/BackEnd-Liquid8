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
        Schema::create('so_colors', function (Blueprint $table) {
            $table->id();
            $table->integer('product_damaged')->nullable();
            $table->integer('product_abnormal')->nullable();
            $table->integer('product_lost')->nullable();
            $table->integer('product_addition')->nullable();
            $table->foreignId('summary_so_color_id')
                ->constrained('summary_so_colors')
                ->onDelete('cascade');
            $table->string('color')->nullable();
            $table->integer('total_color')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('so_colors', function (Blueprint $table) {
            $table->dropForeign(['summary_so_color_id']);
        });
        Schema::dropIfExists('so_colors');
    }
};
