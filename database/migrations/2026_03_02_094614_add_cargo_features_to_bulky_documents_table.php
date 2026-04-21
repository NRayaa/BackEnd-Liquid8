<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->enum('type', ['cargo offline', 'cargo online'])->nullable()->after('status_bulky');
            $table->enum('is_sale', ['not sale', 'ready', 'sale'])->default('not sale')->after('type');
            $table->float('length')->nullable();
            $table->float('width')->nullable();
            $table->float('height')->nullable();
            $table->float('weight')->nullable();
            $table->string('fleet_estimation')->nullable();
            $table->string('cargo_photo')->nullable();
            $table->string('cargo_file')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'is_sale',
                'length',
                'width',
                'height',
                'weight',
                'fleet_estimation',
                'cargo_photo',
                'cargo_file'
            ]);
        });
    }
};
