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
        Schema::create('product_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('riwayat_check_id')->constrained('riwayat_checks')->onDelete('cascade');
            $table->string('code_document')->nullable();
            $table->string('old_barcode_product')->nullable();
            $table->decimal('old_price_product', 15,2)->nullable();
            $table->enum('type', ['damaged', 'abnormal']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_defects');
    }
};
