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
        Schema::create('sku_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document')->unique();
            $table->string('base_document');
            $table->integer('total_column_document');
            $table->integer('total_column_in_document');
            $table->date('date_document');
            $table->enum('status_document', ['pending', 'in progress', 'done'])->default('pending');
            $table->string('custom_barcode')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_documents');
    }
};
