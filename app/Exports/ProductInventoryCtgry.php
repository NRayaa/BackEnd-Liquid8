<?php

namespace App\Exports;

use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductInventoryCtgry implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $query; // Menyimpan query untuk filter

    public function __construct(Request $request)
    {
        $this->query = $request->input('q'); // Ambil input query
    }


    public function query()
    {
        // Select semua kolom dari tabel New_product
        $productQuery = New_product::select(
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product',
            'created_at',
            'new_discount',
            'display_price',
            DB::raw('DATEDIFF(CURRENT_DATE, created_at) as days_since_created')
        )->whereNotNull('new_category_product')
            ->where('new_tag_product', NULL)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($status) {
                $status->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1')
                    ->orWhere('type', 'type2');
            });

        $bundleQuery = Bundle::select(
            DB::raw('NULL as code_document'),
            DB::raw('NULL as old_barcode_product'),
            'barcode_bundle as new_barcode_product',
            'name_bundle as new_name_product',
            DB::raw('NULL as new_quantity_product'),
            'total_price_custom_bundle as new_price_product',
            DB::raw('NULL as old_price_product'),
            DB::raw("CASE WHEN product_status = 'not sale' THEN 'display' ELSE product_status END as new_status_product"),
            DB::raw('NULL as new_quality'),
            'category as new_category_product',
            DB::raw('NULL as new_tag_product'),
            'created_at',
            DB::raw('NULL as new_discount'),
            'total_price_custom_bundle as display_price',
            DB::raw('DATEDIFF(CURRENT_DATE, created_at) as days_since_created')
        )->where('total_price_custom_bundle', '>=', 100000);

        return $productQuery->union($bundleQuery)
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Old Barcode Product',
            'New Barcode Product',
            'New Name Product',
            'New Quantity Product',
            'New Price Product',
            'Old Price Product',
            'New Status Product',
            'New Quality',
            'New Category Product',
            'New Tag Product',
            'created_at',
            'New Discount',
            'Display Price',
            'Days Since Created',
        ];
    }

    public function map($product): array
    {
        return [
            $product->code_document,
            $product->old_barcode_product,
            $product->new_barcode_product,
            $product->new_name_product,
            $product->new_quantity_product,
            $product->new_price_product,
            $product->old_price_product,
            $product->new_status_product,
            $product->new_quality,
            $product->new_category_product,
            $product->new_tag_product,
            $product->created_at,
            $product->new_discount,
            $product->display_price,
            $product->days_since_created,
        ];
    }

    /**
     * Chunk size per read operation
     */
    public function chunkSize(): int
    {
        return 500;
    }
}
