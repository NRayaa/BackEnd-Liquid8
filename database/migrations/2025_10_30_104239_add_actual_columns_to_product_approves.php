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
        Schema::table('product_approves', function (Blueprint $table) {
            $table->decimal('actual_old_price_product', 15, 2)->nullable();
            $table->json('actual_new_quality')->nullable();
        });

        // Copy data dari kolom existing ke kolom baru dengan chunking untuk keamanan
        
        // Set memory limit dan timeout untuk migration
        ini_set('memory_limit', '512M');
        set_time_limit(600); // 10 menit

        $chunkSize = 1000;
        $totalRecords = DB::table('product_approves')->count();
        $processedRecords = 0;

        if ($totalRecords > 0) {
            DB::table('product_approves')
                ->orderBy('id')
                ->chunk($chunkSize, function ($products) use (&$processedRecords) {
                    foreach ($products as $product) {
                        DB::table('product_approves')
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
        Schema::table('product_approves', function (Blueprint $table) {
            $table->dropColumn(['actual_old_price_product', 'actual_new_quality']);
        });
    }
};
