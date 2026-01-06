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
            // Tambahkan kolom untuk data non jika belum ada
            if (!Schema::hasColumn('riwayat_checks', 'total_data_non')) {
                $table->integer('total_data_non')->nullable()->default(0)->after('total_data_abnormal');
            }
            
            if (!Schema::hasColumn('riwayat_checks', 'percentage_non')) {
                $table->decimal('percentage_non', 5, 2)->nullable()->after('percentage_abnormal');
            }

            if (!Schema::hasColumn('riwayat_checks', 'value_data_non')) {
                $table->decimal('value_data_non', 15, 2)->nullable()->after('value_data_abnormal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('riwayat_checks', function (Blueprint $table) {
            $table->dropColumn(['total_data_non', 'percentage_non', 'value_data_non']);
        });
    }
};
