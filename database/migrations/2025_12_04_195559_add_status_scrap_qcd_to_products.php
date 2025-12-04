<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving', 'scrap_qcd'";

        DB::statement("ALTER TABLE staging_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");

        DB::statement("ALTER TABLE new_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $statuses = "'display', 'expired', 'promo', 'bundle', 'palet', 'dump', 'sale', 'migrate', 'repair', 'pending_delete', 'slow_moving'";
        DB::statement("ALTER TABLE staging_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
        DB::statement("ALTER TABLE new_products MODIFY COLUMN new_status_product ENUM($statuses) NULL");
    }
};
