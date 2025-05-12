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
        Schema::create('palet_sync_approves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('palet_id')
                ->constrained('palets')
                ->onDelete('cascade');
            $table->unique('palet_id');
            $table->enum('status', ['sync', 'not_sync'])->default('not_sync')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('palet_sync_approves');
    }
};
