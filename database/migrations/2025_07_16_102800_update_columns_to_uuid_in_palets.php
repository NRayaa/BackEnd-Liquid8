<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop all foreign keys untuk kolom tersebut
        Schema::table('palets', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['product_condition_id']);
            $table->dropForeign(['product_status_id']);
            $table->dropForeign(['category_palet_id']);

            $table->dropIndex('palets_warehouse_id_foreign');
            $table->dropIndex('palets_product_condition_id_foreign');
            $table->dropIndex('palets_product_status_id_foreign');
            $table->dropIndex('palets_category_palet_id_foreign');
        });

        // 2. Ubah semua kolom tersebut ke UUID (tanpa foreign key)
        Schema::table('palets', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->change();
            $table->uuid('product_condition_id')->nullable()->change();
            $table->uuid('product_status_id')->nullable()->change();
            $table->uuid('category_palet_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rollback ke unsignedBigInteger dan tambahkan foreign key lagi
        Schema::table('palets', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();
            $table->unsignedBigInteger('product_condition_id')->nullable()->change();
            $table->unsignedBigInteger('product_status_id')->nullable()->change();
            $table->unsignedBigInteger('category_palet_id')->nullable()->change();
        });

        Schema::table('palets', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
            $table->foreign('product_condition_id')->references('id')->on('product_conditions')->onDelete('set null');
            $table->foreign('product_status_id')->references('id')->on('product_statuses')->onDelete('set null');
            $table->foreign('category_palet_id')->references('id')->on('category_palets')->onDelete('set null');
        });
    }
};
