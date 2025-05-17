<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modifikasi nilai ENUM menggunakan SQL mentah
        DB::statement("ALTER TABLE notifications MODIFY COLUMN status ENUM('display','pending', 'done', 'staging', 'sale', 'inventory') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan nilai ENUM ke kondisi sebelumnya
        DB::statement("ALTER TABLE notifications MODIFY COLUMN status ENUM('display','pending', 'done', 'staging', 'sale') NOT NULL");
    }
};