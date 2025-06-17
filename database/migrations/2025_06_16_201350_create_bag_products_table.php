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
        Schema::create('bag_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('bulky_document_id')->constrained('bulky_documents')->onDelete('cascade');
            $table->integer('total_product')->default(0)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bag_products', function (Blueprint $table) {
            if (Schema::hasColumn('bag_products', 'user_id')) {
                $table->dropForeign(['user_id']);
            }
            if (Schema::hasColumn('bag_products', 'bulky_document_id')) {
                $table->dropForeign(['bulky_document_id']);
            }
        });
        Schema::dropIfExists('bag_products');
    }
};
