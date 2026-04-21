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
        Schema::table('bkl_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_id')->nullable()->after('code_document_bkl');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bkl_documents', function (Blueprint $table) {
            $table->dropColumn('destination_id');
        });
    }
};
