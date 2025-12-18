<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'scrap_qcd'";

            DB::statement("ALTER TABLE migrate_bulky_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('migrate_bulky_products', function (Blueprint $table) {
            $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete'";
            DB::statement("ALTER TABLE migrate_bulky_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        });
    }
};
