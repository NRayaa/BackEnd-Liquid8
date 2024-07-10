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
        Schema::table('migrates_documents_and_migrates', function (Blueprint $table) {
            Schema::table('migrates', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->after('id');
            });
    
            Schema::table('migrate_documents', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->after('id');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migrates_documents_and_migrates', function (Blueprint $table) {
            Schema::table('migrates_documents', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
    
            Schema::table('migrates', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        });
    }
};