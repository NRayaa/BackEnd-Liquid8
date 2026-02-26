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
        
        Schema::create('damaged_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_damaged')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->integer('total_product')->default(0);
            $table->decimal('total_new_price', 15, 2)->default(0);
            $table->decimal('total_old_price', 15, 2)->default(0);
            $table->enum('status', ['proses', 'lock', 'selesai'])->default('proses');
            $table->timestamps();
        });

        
        Schema::create('non_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_non')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->integer('total_product')->default(0);
            $table->decimal('total_new_price', 15, 2)->default(0);
            $table->decimal('total_old_price', 15, 2)->default(0);
            $table->enum('status', ['proses', 'lock', 'selesai'])->default('proses');
            $table->timestamps();
        });

        Schema::create('abnormal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code_document_abnormal')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->integer('total_product')->default(0);
            $table->decimal('total_new_price', 15, 2)->default(0);
            $table->decimal('total_old_price', 15, 2)->default(0);
            $table->enum('status', ['proses', 'lock', 'selesai'])->default('proses');
            $table->timestamps();
        });

        
        Schema::create('damaged_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damaged_document_id')->constrained('damaged_documents');
            $table->unsignedBigInteger('productable_id');
            $table->string('productable_type');
            $table->timestamps();
            $table->index(['productable_id', 'productable_type']);
        });

        Schema::create('non_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('non_document_id')->constrained('non_documents');
            $table->unsignedBigInteger('productable_id');
            $table->string('productable_type');
            $table->timestamps();
            $table->index(['productable_id', 'productable_type']);
        });

        Schema::create('abnormal_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abnormal_document_id')->constrained('abnormal_documents');
            $table->unsignedBigInteger('productable_id');
            $table->string('productable_type');
            $table->timestamps();
            $table->index(['productable_id', 'productable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('non_document_items');
        Schema::dropIfExists('damaged_document_items');
        Schema::dropIfExists('abnormal_document_items');
        Schema::dropIfExists('non_documents');
        Schema::dropIfExists('damaged_documents');
        Schema::dropIfExists('abnormal_documents');
    }
};