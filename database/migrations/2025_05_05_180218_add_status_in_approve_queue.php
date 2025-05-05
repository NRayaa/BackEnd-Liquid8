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
        Schema::table('approve_queues', function (Blueprint $table) {
            $table->enum('status', ['0', '1'])->after('new_category_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approve_queues', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
