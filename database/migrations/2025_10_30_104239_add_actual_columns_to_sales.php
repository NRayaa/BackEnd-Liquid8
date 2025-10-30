<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('actual_product_old_price_sale', 15, 2)->nullable();
            $table->string('actual_status_product')->nullable();
        });

        // Set memory limit dan timeout untuk migration
        ini_set('memory_limit', '512M');
        set_time_limit(600); // 10 menit

        $chunkSize = 1000;
        $totalRecords = DB::table('sales')->count();
        $processedRecords = 0;

        if ($totalRecords > 0) {
            DB::table('sales')
                ->orderBy('id')
                ->chunk($chunkSize, function ($products) use (&$processedRecords) {
                    foreach ($products as $product) {
                        DB::table('sales')
                            ->where('id', $product->id)
                            ->update([
                                'actual_product_old_price_sale' => $product->product_old_price_sale,
                                'actual_status_product' => $product->status_product,
                            ]);
                    }

                    $processedRecords += count($products);
                });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['actual_product_old_price_sale', 'actual_status_product']);
        });
    }
};
