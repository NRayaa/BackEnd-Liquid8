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
        Schema::create('scrap_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_scrap')->unique(); 
            $table->foreignId('user_id')->constrained('users'); 
            $table->integer('total_product')->default(0);
            $table->decimal('total_new_price', 15, 2)->default(0); 
            $table->decimal('total_old_price', 15, 2)->default(0); 
            $table->enum('status', ['proses', 'selesai']); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrap_documents');
    }
};
