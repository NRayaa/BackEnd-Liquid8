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
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->decimal('new_discount', 15, 2)->nullable()->default(0)->after('new_tag_product');
            $table->decimal('display_price', 15, 2)->nullable()->default(0)->after('new_discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $table->dropColumn(['new_discount', 'display_price']);
        });
    }
};
