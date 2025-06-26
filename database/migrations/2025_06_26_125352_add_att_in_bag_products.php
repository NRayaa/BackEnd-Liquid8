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
        Schema::table('bag_products', function (Blueprint $table) {
            $table->string('barcode_bag')->nullable()->after('id');
            $table->string('name_bag')->nullable()->after('barcode_bag');
            $table->string('category_bag')->nullable()->after('name_bag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bag_products', function (Blueprint $table) {
            $table->dropColumn('barcode_bag');
            $table->dropColumn('name_bag');
            $table->dropColumn('category_bag');
        });
    }
};
