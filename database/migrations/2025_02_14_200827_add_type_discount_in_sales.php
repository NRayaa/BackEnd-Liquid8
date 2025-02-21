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
            $table->enum('type_discount', ['new','old'])->nullable()->after('new_discount_sale');
        });
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->enum('type_discount', ['new','old'])->nullable()->after('new_discount_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('type_discount')->after('new_discount_sale');
        });
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->dropColumn('type_discount')->after('new_discount_sale');
        });
    }
};
