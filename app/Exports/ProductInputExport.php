<?php

namespace App\Exports;

use App\Models\User;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\ProductInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductInputExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $query; // Menyimpan query untuk filter

    public function __construct()
    {
    }


    public function query()
    {
        // Select semua kolom dari tabel New_product
        $productQuery = ProductInput::select(
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_category_product',
            'created_at',
            'user_id',
        );                

        return $productQuery->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Isi Barang',
            'Qty',
            'Before Discount',
            'Category',
            'Discount',
            'After Discount',
            'Barcode Liquid',
            'Admin',
            'Tgl Pengerjaan',
        ];
    }

    public function map($product): array
    {
        $user = User::find($product->user_id);
        $category = Category::where('name_category', $product->new_category_product)->first();
        return [
            $product->new_name_product,
            $product->new_quantity_product,
            $product->old_price_product,
            $product->new_category_product,
            $category->discount_category,
            $product->new_price_product,
            $product->new_barcode_product,
            $user->username,
            $product->created_at,

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
