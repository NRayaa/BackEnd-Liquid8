<?php

namespace App\Exports;

use App\Models\New_product;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProductAbnormalExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize
{
    use Exportable;

    protected $query;

    public function __construct(Request $request)
    {
        $this->query = $request->input('q');
    }

    public function query()
    {
        return New_product::query()
            ->whereNotNull('new_quality->abnormal')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale']) 
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_quality',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                // 'new_date_in_product'
            );
    }

    public function headings(): array
    {
        return [
            'Code Document',
            'Old Barcode Product',
            'New Barcode Product',
            'Keterangan',
            'New Name Product',
            'New Quantity Product',
            'New Price Product',
            'Old Price Product',
            'New Status Product',
            'New Category Product',
            'New Tag Product',
            // 'New Date In Product',
        ];
    }

    public function map($product): array
    {
        $qualityArray = is_string($product->new_quality) 
            ? json_decode($product->new_quality, true) 
            : $product->new_quality;

        $keterangan = $qualityArray['abnormal'] ?? '-';

        return [
            $product->code_document,
            $product->old_barcode_product,
            $product->new_barcode_product,
            $keterangan,
            $product->new_name_product,
            $product->new_quantity_product,
            $product->new_price_product,
            $product->old_price_product,
            $product->new_status_product,
            $product->new_category_product,
            $product->new_tag_product,
            // $product->new_date_in_product,
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }
}