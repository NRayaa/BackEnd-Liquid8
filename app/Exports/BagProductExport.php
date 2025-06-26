<?php

namespace App\Exports;

use App\Models\BagProducts;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class BagProductExport implements FromCollection, WithHeadings
{
    protected $bagProduct;

    public function __construct($bagProduct)
    {
        $this->bagProduct = $bagProduct;
    }

    public function collection()
    {
        $rows = [];
        foreach ($this->bagProduct as $bag) {
            foreach ($bag->bulkySales as $sale) {
                $rows[] = [
                    'Bag Barcode' => $bag->barcode_bag,
                    'Bag Name' => $bag->name_bag,
                    'Total Product' => $bag->total_product,
                    'Status' => $bag->status,
                    'Barcode Bulky Sale' => $sale->barcode_bulky_sale,
                    'Name Product Bulky Sale' => $sale->name_product_bulky_sale,
                    'Old Price Bulky Sale' => $sale->old_price_bulky_sale,
                    'After Price Bulky Sale' => $sale->after_price_bulky_sale,
                ];
            }
        }
        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'Bag Barcode',
            'Bag Name',
            'Total Product',
            'Status',
            'Barcode Bulky Sale',
            'Name Product Bulky Sale',
            'Old Price Bulky Sale',
            'After Price Bulky Sale',
        ];
    }
}
