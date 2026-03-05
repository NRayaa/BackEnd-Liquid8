<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bag_products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('bulky_document_id');
            $table->enum('type', ['category', 'color'])->default('category')->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('bag_products', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'type']);
        });
    }
};