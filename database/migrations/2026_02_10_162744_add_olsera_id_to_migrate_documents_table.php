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
        Schema::table('migrate_documents', function (Blueprint $table) {
            $table->string('olsera_purchase_id')->nullable()->after('code_document_migrate');
            $table->text('olsera_response_log')->nullable()->after('olsera_purchase_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migrate_documents', function (Blueprint $table) {
            $table->dropColumn(['olsera_purchase_id', 'olsera_response_log']);
        });
    }
};
