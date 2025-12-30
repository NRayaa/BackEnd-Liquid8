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
        Schema::table('product_defects', function (Blueprint $table) {
            DB::statement("ALTER TABLE product_defects MODIFY COLUMN type ENUM('damaged', 'abnormal', 'non') NOT NULL");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_defects', function (Blueprint $table) {
            DB::table('product_defects')->where('type', 'non')->delete();
            DB::statement("ALTER TABLE product_defects MODIFY COLUMN type ENUM('damaged', 'abnormal') NOT NULL");
        });
    }
};
