<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropForeign('bulky_documents_buyer_id_foreign');
            
            $table->foreign('buyer_id', 'bulky_documents_buyer_id_foreign')
                  ->references('id')->on('buyers')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropForeign('bulky_documents_buyer_id_foreign');
            
            $table->foreign('buyer_id', 'bulky_documents_buyer_id_foreign')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });
    }
};
