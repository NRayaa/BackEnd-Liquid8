<?php

namespace App\Exports;

use App\Models\New_product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProductSheet implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithTitle
{
    protected $tag;

    public function __construct($tag)
    {
        $this->tag = $tag; // Tag untuk sheet ini
    }

    public function query()
    {
        return New_product::where('new_tag_product', $this->tag)
            ->whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'display');
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
            'New Tag Product',
            'Created At',
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
            $product->new_tag_product,
            $product->created_at,
            $product->new_discount,
            $product->display_price,
            $product->days_since_created,
        ];
    }

    /**
     * Ukuran chunk untuk pembacaan
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Tentukan nama sheet
     */
    public function title(): string
    {
        return $this->tag; // Nama sheet berdasarkan tag
    }
}
