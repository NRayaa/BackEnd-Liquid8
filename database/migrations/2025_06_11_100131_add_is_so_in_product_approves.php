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
         Schema::table('product_approves', function (Blueprint $table) {
            $table->enum('is_so', ['check', 'done', 'lost', 'addition'])->nullable();
            $table->foreignId('user_so')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_approves', function (Blueprint $table) {
            $table->dropForeign(['user_so']);
            $table->dropColumn('user_so');
            $table->dropColumn('is_so');
        });
    }
};
