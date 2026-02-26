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
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('source', ['staging', 'display'])->nullable();
            $table->integer('total_data')->default(0);
            $table->decimal('total_new_price_product', 15, 2)->nullable()->default(0);
            $table->decimal('total_old_price_product', 15, 2)->nullable()->default(0);
            $table->decimal('total_display_price_product', 15, 2)->nullable()->default(0);
            $table->timestamps();
            // nama boleh sama jika source-nya beda.
            $table->unique(['name', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('racks');
    }
};
