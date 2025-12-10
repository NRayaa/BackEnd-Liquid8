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
        Schema::table('racks', function (Blueprint $table) {
            // Kolom ini menyimpan ID dari Rack Display pasangannya
            $table->foreignId('display_rack_id')
                  ->nullable() // Boleh null (karena Rack Display gak punya parent)
                  ->after('category_id')
                  ->constrained('racks') // Relasi ke tabel racks itu sendiri
                  ->onDelete('cascade'); // Jika Rak Display dihapus, stagingnya ikut terhapus (opsional)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->dropForeign(['display_rack_id']);
            $table->dropColumn('display_rack_id');
        });
    }
};
