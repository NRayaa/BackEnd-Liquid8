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
        Schema::table('new_products', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('new_status_product');
        });


        Schema::table('staging_products', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('new_status_product');
        });

        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('new_status_product');
        });
        
        Schema::table('product__filters', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('new_status_product');
        });
        
        Schema::table('product__bundles', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('new_status_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });

        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });
        
        Schema::table('product__filters', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });
        
        Schema::table('product__bundles', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });
    }
};
