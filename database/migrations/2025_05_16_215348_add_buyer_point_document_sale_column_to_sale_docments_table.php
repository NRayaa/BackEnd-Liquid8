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
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->bigInteger('buyer_point_document_sale')->after('buyer_address_document_sale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_documents', function (Blueprint $table) {
            $table->dropColumn('buyer_point_document_sale');
        });
    }
};
