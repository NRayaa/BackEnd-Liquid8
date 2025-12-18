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
            $table->foreignId('scrap_document_id')->nullable()->after('rack_id')
                ->constrained('scrap_documents');
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->foreignId('scrap_document_id')->nullable()->after('rack_id')
                ->constrained('scrap_documents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('new_products', function (Blueprint $table) {
            $table->dropForeign(['scrap_document_id']);
            $table->dropColumn('scrap_document_id');
        });

        Schema::table('staging_products', function (Blueprint $table) {
            $table->dropForeign(['scrap_document_id']);
            $table->dropColumn('scrap_document_id');
        });
    }
};
