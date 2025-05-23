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
            $table->string('status_product_before')->after('name_product_bulky_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropColumn('status_product_before');
        });
    }
};
