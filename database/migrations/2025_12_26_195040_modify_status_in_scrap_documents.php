<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrap_documents', function (Blueprint $table) {
            DB::statement("ALTER TABLE scrap_documents MODIFY COLUMN status ENUM('proses', 'lock', 'selesai') NOT NULL DEFAULT 'proses'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('scrap_documents')->where('status', 'lock')->update(['status' => 'proses']);
        DB::statement("ALTER TABLE scrap_documents MODIFY COLUMN status ENUM('proses', 'selesai') NOT NULL DEFAULT 'proses'");
    }
};
