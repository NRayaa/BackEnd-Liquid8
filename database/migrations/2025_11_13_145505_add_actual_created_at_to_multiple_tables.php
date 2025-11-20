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
        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('actual_created_at')->nullable()->after('created_at');
        });
         
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->timestamp('actual_created_at')->nullable()->after('created_at');
            $table->decimal('display_price', 15, 2)->default(0);
        });
        
        Schema::table('palet_products', function (Blueprint $table) {
            $table->timestamp('actual_created_at')->nullable()->after('created_at');
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->timestamp('actual_created_at')->nullable()->after('created_at');
        });
        Schema::table('repair_products', function (Blueprint $table) {
            $table->timestamp('actual_created_at')->nullable()->after('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('actual_created_at');
        });
        
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropColumn(['actual_created_at', 'display_price']);
        });
        
        Schema::table('palet_products', function (Blueprint $table) {
            $table->dropColumn('actual_created_at');
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->dropColumn('actual_created_at');
        });
        
        Schema::table('repair_products', function (Blueprint $table) {
            $table->dropColumn('actual_created_at');
        });
    }
};
