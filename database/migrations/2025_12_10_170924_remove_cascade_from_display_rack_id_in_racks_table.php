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
            $table->dropForeign(['display_rack_id']);
            $table->foreign('display_rack_id')
                ->references('id')
                ->on('racks')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->dropForeign(['display_rack_id']);
            $table->foreign('display_rack_id')
                ->references('id')
                ->on('racks')
                ->onDelete('cascade');
        });
    }
};
