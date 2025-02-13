<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // note: cara ini di gunakan karena kolom foreign key tidak bisa di change secara langsung
        Schema::table('user_scans', function (Blueprint $table) {
            $table->dropForeign(['format_barcode_id']);
            $table->dropColumn('format_barcode_id');
        });
        Schema::table('user_scans', function (Blueprint $table) {
            $table->foreignId('format_barcode_id')->nullable()->constrained('format_barcodes');
        });
    }

    public function down()
    {
        // note: cara ini di gunakan karena kolom foreign key tidak bisa di change secara langsung
        Schema::table('user_scans', function (Blueprint $table) {
            $table->dropForeign(['format_barcode_id']);
            $table->dropColumn('format_barcode_id');
        });
        Schema::table('user_scans', function (Blueprint $table) {
            $table->foreignId('format_barcode_id')->constrained();
        });
    }
};
