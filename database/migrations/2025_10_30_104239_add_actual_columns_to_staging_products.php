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
        Schema::table('staging_products', function (Blueprint $table) {
            $table->decimal('actual_old_price_product', 15, 2)->nullable();
            $table->json('actual_new_quality')->nullable();
        });
        
        // Set memory limit dan timeout untuk migration
        ini_set('memory_limit', '-1'); // Unlimited memory
        set_time_limit(0); // Unlimited execution time

        $chunkSize = 1000;
        $totalRecords = DB::table('staging_products')->count();
        $processedRecords = 0;

        if ($totalRecords > 0) {
            DB::table('staging_products')
                ->orderBy('id')
                ->chunk($chunkSize, function ($products) use (&$processedRecords) {
                    foreach ($products as $product) {
                        DB::table('staging_products')
                            ->where('id', $product->id)
                            ->update([
                                'actual_old_price_product' => $product->old_price_product,
                                'actual_new_quality' => $product->new_quality,
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
        Schema::table('staging_products', function (Blueprint $table) {
            $table->dropColumn(['actual_old_price_product', 'actual_new_quality']);
        });
    }
};
