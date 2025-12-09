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
        Schema::create('bkl_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bkl_document_id')->constrained('bkl_documents');
            $table->foreignId('color_tag_id')->nullable()->constrained('color_tags');
            $table->integer('qty');
            $table->enum('type', ['in', 'out']);
            $table->boolean('is_damaged')->nullable()->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bkl_items');
    }
};
