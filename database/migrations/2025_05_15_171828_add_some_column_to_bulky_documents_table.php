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
            $table->foreignId('user_id')->after('code_document_bulky');
            $table->string('name_user')->after('user_id');
            $table->string('category_bulky')->after('after_price_bulky');
            $table->foreignId('buyer_id')->after('total_old_price_bulky');
            $table->string('name_buyer')->after('buyer_id');
            $table->enum('status_bulky', ['proses', 'selesai'])->after('name_buyer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulky_documents', function (Blueprint $table) {
            $table->dropColumn('user_id');
            $table->dropColumn('name_user');
            $table->dropColumn('buyer_id');
            $table->dropColumn('name_buyer');
            $table->dropColumn('category_bulky');
            $table->dropColumn('status_bulky');
        });
    }
};
