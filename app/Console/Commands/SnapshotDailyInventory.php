<?php

namespace App\Console\Commands;

use App\Models\Bundle;
use App\Models\DailyInventorySnapshot;
use App\Models\New_product;
use App\Models\StagingProduct;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SnapshotDailyInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:snapshotDailySummary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menyimpan total produk dan harga untuk saldo awal besok';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mulai menghitung inventory...');

        // 1. Category New Product
        $categoryNewProduct = New_product::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($query) {
                $query->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })
            ->groupBy('category_product');

        // 2. Category Bundle
        $categoryBundle = Bundle::selectRaw('
                category as category_product,
                COUNT(category) as total_category,
                SUM(total_price_custom_bundle) as total_price_category
            ')
            ->whereNotNull('category')
            ->where('name_color', null)
            ->whereNotIn('product_status', ['bundle'])
            ->groupBy('category_product');

        $categoryCount = $categoryNewProduct->union($categoryBundle)->get();

        // 3. Tag Product
        $tagProductCount = New_product::selectRaw(' 
                new_tag_product as tag_product,
                COUNT(new_tag_product) as total_tag_product,
                SUM(new_price_product) as total_price_tag_product
            ')
            ->whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'display')
            ->groupBy('new_tag_product')
            ->get();

        // 4. Staging Product
        $categoryStagingProduct = StagingProduct::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($query) {
                $query->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })
            ->groupBy('category_product')
            ->get();

        $totalAllProduct = $categoryCount->sum('total_category')
            + $tagProductCount->sum('total_tag_product')
            + $categoryStagingProduct->sum('total_category');

        $totalAllProductPrice = $categoryCount->sum('total_price_category')
            + $tagProductCount->sum('total_price_tag_product')
            + $categoryStagingProduct->sum('total_price_category');

        DailyInventorySnapshot::updateOrCreate(
            ['snapshot_date' => Carbon::now()->toDateString()],
            [
                'total_qty' => $totalAllProduct,
                'total_price' => $totalAllProductPrice
            ]
        );

        $this->info("Berhasil disimpan! Qty: $totalAllProduct, Price: $totalAllProductPrice");
    }
}
