<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProductInventoryCtgry implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    use Exportable;

    protected $dataCollection;

    public function __construct($dataCollection)
    {
        $this->dataCollection = $dataCollection;
    }

    public function collection()
    {
        return $this->dataCollection;
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
            'Created At',
            'New Discount',
            'Display Price',
            'Days Since Created',
        ];
    }

    // Pembersih Karakter Unicode / Emoji yang rusak
    private function cleanString($string)
    {
        if (empty($string)) return '';
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        $string = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
        return $string;
    }

    public function map($product): array
    {
        return [
            $this->cleanString($product->code_document),
            $this->cleanString($product->old_barcode_product),
            $this->cleanString($product->new_barcode_product),
            $this->cleanString($product->new_name_product),
            $product->new_quantity_product,
            $product->new_price_product,
            $product->old_price_product,
            $this->cleanString($product->new_status_product),
            $this->cleanString($product->new_quality),
            $this->cleanString($product->new_category_product),
            $this->cleanString($product->new_tag_product),
            $this->cleanString($product->created_at),
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
