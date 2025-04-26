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
        Schema::table('archive_storages', function (Blueprint $table) {
            $table->string('category_product')->nullable()->change();
            $table->bigInteger('total_category')->nullable()->change();
            $table->string('color')->nullable()->after('category_product');
            $table->bigInteger('total_color')->nullable()->after('total_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archive_storages', function (Blueprint $table) {
            $table->string('category_product')->nullable(false)->change();
            $table->bigInteger('total_category')->nullable(false)->change();
            $table->dropColumn('color');
        });
    }
};
