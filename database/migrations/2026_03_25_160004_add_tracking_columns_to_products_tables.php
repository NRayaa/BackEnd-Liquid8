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
        Schema::table('new_products', function (Blueprint $table) {       
            $table->timestamp('date_out')->nullable();
            $table->string('type_out', 50)->nullable();
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->timestamp('date_out')->nullable();
            $table->string('type_out', 50)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->dropColumn(['date_out', 'type_out']);
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->dropColumn(['date_out', 'type_out']);
        });
    }
};