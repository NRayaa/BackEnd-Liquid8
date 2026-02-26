<?php

namespace App\Exports;

use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductExportMasSugeng implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $query;

    public function __construct(Request $request)
    {
        $this->query = $request->input('q');
        // Conservative memory settings
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 900);
    }

    public function query()
    {
        return New_product::whereNotNull('new_category_product')
            ->whereNull('new_tag_product')
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function ($status) {
                $status->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired');
            })
            ->whereDate('new_date_in_product', '>=', '2025-05-01')
            ->whereDate('new_date_in_product', '<', '2025-06-01')
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_status_product',
                'new_date_in_product',
                'new_category_product',
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
            'New Date In Product',
            'New Category Product',

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
            'display',
            $product->new_date_in_product,
            $product->new_category_product,

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
