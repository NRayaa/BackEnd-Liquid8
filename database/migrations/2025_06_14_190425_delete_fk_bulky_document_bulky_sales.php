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
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropForeign(['bulky_document_id']);
            $table->foreignId('bulky_document_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            // Kembalikan ke kondisi semula (non-nullable dengan foreign key)
            $table->foreignId('bulky_document_id')->nullable(false)->change();
            $table->foreign('bulky_document_id')->references('id')->on('bulky_documents')->cascadeOnDelete();
        });
    }
};
