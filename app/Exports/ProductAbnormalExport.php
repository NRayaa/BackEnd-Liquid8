<?php

namespace App\Exports;

use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $displayQuery = New_product::query()
            ->whereNotNull('new_quality->abnormal')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale']) 
            ->select(
                'id',
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
                DB::raw("'Display / Inventory' as source_table")
            );

        $stagingQuery = StagingProduct::query()
            ->whereNotNull('new_quality->abnormal')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale']) 
            ->select(
                'id',
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
                DB::raw("'Staging' as source_table")
            );

        return $displayQuery->unionAll($stagingQuery)->orderBy(DB::raw('id'), 'ASC');
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
            'Source Table',
        ];
    }

    private function sanitizeString($string)
    {
        if (!is_string($string) || empty($string)) {
            return $string;
        }

        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);

        return $string;
    }

    public function map($product): array
    {
        $qualityArray = is_string($product->new_quality) 
            ? json_decode($product->new_quality, true) 
            : $product->new_quality;

        $keterangan = $qualityArray['abnormal'] ?? '-';

        return [
            $this->sanitizeString($product->code_document),
            $this->sanitizeString($product->old_barcode_product),
            $this->sanitizeString($product->new_barcode_product),
            $this->sanitizeString($keterangan),
            $this->sanitizeString($product->new_name_product),
            $product->new_quantity_product,
            $product->new_price_product,
            $product->old_price_product,
            $this->sanitizeString($product->new_status_product),
            $this->sanitizeString($product->new_category_product),
            $this->sanitizeString($product->new_tag_product),
            $product->source_table, 
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }
}