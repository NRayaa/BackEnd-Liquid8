<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->json('brand_ids')->nullable();
            $table->json('brand_names')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->dropColumn(['brand_ids', 'brand_names']);
        });
    }
};
