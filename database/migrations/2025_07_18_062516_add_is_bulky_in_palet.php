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
        Schema::table('palets', function (Blueprint $table) {
            $table->enum('is_bulky', ['done', 'waiting_list', 'waiting_approve'])->nullable()->after('id');
            $table->index('is_bulky', 'palets_is_bulky_index');
            $table->foreignId('user_id')->nullable()->constrained('users')->after('is_bulky');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('palets', function (Blueprint $table) {
            $table->dropIndex('palets_is_bulky_index');
            $table->dropColumn('is_bulky');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
