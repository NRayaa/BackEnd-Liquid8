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
        Schema::table('riwayat_checks', function (Blueprint $table) {
            $table->decimal('total_price_in', 13, 2)->after('total_data_in')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('riwayat_checks', function (Blueprint $table) {
            $table->dropColumn('total_price_in');
        });
    }
};
