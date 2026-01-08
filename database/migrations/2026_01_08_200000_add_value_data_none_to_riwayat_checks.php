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
            $table->dropColumn(['value_data_non']);
        });
    }
};
