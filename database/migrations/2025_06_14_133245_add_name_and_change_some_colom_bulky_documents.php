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
            // Ubah kolom menjadi nullable
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('name_user')->nullable()->change();
            $table->unsignedBigInteger('buyer_id')->nullable()->change();
            $table->string('name_buyer')->nullable()->change();
            $table->string('name_document')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            // Kembalikan ke not nullable (hati-hati, pastikan tidak ada data NULL)
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->string('name_user')->nullable(false)->change();
            $table->unsignedBigInteger('buyer_id')->nullable(false)->change();
            $table->string('name_buyer')->nullable(false)->change();
            $table->dropColumn('name_document');
        });
    }
};
