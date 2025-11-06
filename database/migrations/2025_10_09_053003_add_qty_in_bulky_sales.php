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
            $table->integer('qty')->nullable();
            $table->string('code_document')->nullable();
            $table->string('old_barcode_product')->nullable();
            $table->date('new_date_in_product')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_sales', function (Blueprint $table) {
            $table->dropColumn('qty');
            $table->dropColumn('code_document');
            $table->dropColumn('old_barcode_product');
            $table->dropColumn('new_date_in_product');
        });
    }
};
