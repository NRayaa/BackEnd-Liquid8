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
        Schema::table('rack_histories', function (Blueprint $table) {
            Schema::table('rack_histories', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable()->after('rack_id');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rack_histories', function (Blueprint $table) {
            $table->dropColumn('product_id');
        });
    }
};
