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

class ProductAbnormalExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $query; // Menyimpan query untuk filter

    public function __construct(Request $request)
    {
        $this->query = $request->input('q'); // Ambil input query
    }


    public function query()
    {
        return New_product::whereNotNull('new_quality->abnormal')
            ->select(
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
            );
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
