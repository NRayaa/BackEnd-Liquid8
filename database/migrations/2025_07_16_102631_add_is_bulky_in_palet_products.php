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
        Schema::table('palet_products', function (Blueprint $table) {
            $table->enum('is_bulky', ['yes', 'no'])->nullable()->after('id');
            $table->index('is_bulky', 'idx_palet_products_is_bulky');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('palet_products', function (Blueprint $table) {
            $table->dropIndex('idx_palet_products_is_bulky');
            $table->dropColumn('is_bulky');
        });
    }
};
