<?php

namespace App\Console\Commands;

use App\Models\Bundle;
use App\Models\DailyInventorySnapshot;
use App\Models\New_product;
use App\Models\SkuProduct;
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

       $categoryNewProduct = New_product::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category, 
                SUM(new_price_product) as total_price_category,
                SUM(old_price_product) as before_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            // ->whereNotNull('is_so')
            // ->where('is_so', 'done')
            // ->whereNull('user_so')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($query) {
                $query->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })
            ->groupBy('category_product');

        $categoryBundle = Bundle::selectRaw('
                category as category_product,
                COUNT(category) as total_category,
                SUM(total_price_custom_bundle) as total_price_category,
                SUM(total_price_bundle) as before_price_category
            ')
            ->whereNotNull('category')
            ->where('name_color', null)
            // ->whereNotNull('is_so')
            // ->whereNull('user_so')
            ->whereNotIn('product_status', ['bundle'])
            ->groupBy('category_product');

        // merge / gabung kedua hasil query diatas
        $categoryCount = $categoryNewProduct->union($categoryBundle)->get();

        $tagProductCount = New_product::selectRaw(' 
                new_tag_product as tag_product,
                COUNT(new_tag_product) as total_tag_product,
                SUM(new_price_product) as total_price_tag_product,
                SUM(old_price_product) as before_price_tag_product
            ')
            ->whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'display')
            // ->orWhere('new_status_product', 'expired')
            ->groupBy('new_tag_product')
            ->get();

        $categoryStagingProduct = StagingProduct::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category,
                SUM(old_price_product) as before_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            // ->whereNotNull('is_so')
            // ->where('is_so', 'done')
            // ->whereNull('user_so')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($query) {
                $query->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })
            ->groupBy('category_product')
            ->get();

        $slowMovingStaging = StagingProduct::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category,
                SUM(old_price_product) as before_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            // ->whereNotNull('is_so')
            // ->where('is_so', 'done')
            // ->whereNull('user_so')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'slow_moving')
            ->groupBy('category_product')
            ->get();

        $productCategorySlowMov = New_product::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category,
                SUM(old_price_product) as before_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            // ->whereNotNull('is_so')
            // ->whereNull('user_so')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'slow_moving')
            ->groupBy('category_product')->get();

        // sku 
        $skuProduct = SkuProduct::selectRaw('
                COUNT(id) as total_rows,
                SUM(quantity_product) as total_qty,
                SUM(price_product * quantity_product) as total_valuation
            ')
            ->first();

        // sku
        $totalProductSku = $skuProduct->total_qty ?? 0;
        $totalProductSkuPrice = $skuProduct->total_valuation ?? 0;

        $totalAllProduct = $categoryCount->sum('total_category') +
            $tagProductCount->sum('total_tag_product') +
            $categoryStagingProduct->sum('total_category') +
            // $totalProductSku +
            $slowMovingStaging->sum('total_category') +
            $productCategorySlowMov->sum('total_category');

        // total new price
        $totalAllProductPrice = $categoryCount->sum('total_price_category') +
            $tagProductCount->sum('total_price_tag_product') +
            $categoryStagingProduct->sum('total_price_category') +
            // $totalProductSkuPrice +
            $slowMovingStaging->sum('total_price_category') +
            $productCategorySlowMov->sum('total_price_category');

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
