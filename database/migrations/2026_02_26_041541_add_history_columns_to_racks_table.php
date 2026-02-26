<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->timestamp('so_at')->nullable()->after('user_so');
            $table->timestamp('moved_to_display_at')->nullable()->after('so_at');
            $table->unsignedBigInteger('user_display')->nullable()->after('moved_to_display_at');
        });
    }

    public function down(): void
    {
        Schema::table('racks', function (Blueprint $table) {
            $table->dropColumn(['so_at', 'moved_to_display_at', 'user_display']);
        });
    }
};