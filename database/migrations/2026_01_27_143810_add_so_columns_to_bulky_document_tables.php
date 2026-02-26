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
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->enum('is_so', ['check', 'done', 'lost', 'addition'])->nullable();
            $table->string('user_so')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropColumn(['is_so', 'user_so']);
        });
    }
};
