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
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('password')->nullable();
        });

        Schema::table('riwayat_checks', function (Blueprint $table) {
            $table->decimal('value_data_lolos', 13, 2)->nullable();
            $table->decimal('value_data_abnormal', 13, 2)->nullable();
            $table->decimal('value_data_damaged', 13, 2)->nullable();
            $table->decimal('value_data_discrepancy', 13, 2)->nullable();
            $table->boolean('status_file')->nullable()->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
        Schema::table('riwayat_checks', function (Blueprint $table) {
            $table->dropColumn('value_data_lolos');
            $table->dropColumn('value_data_abnormal');
            $table->dropColumn('value_data_damaged');
            $table->dropColumn('value_data_discrepancy');
            $table->dropColumn('status_file');
        });
    }
};
