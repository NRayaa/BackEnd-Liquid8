<?php

namespace App\Exports;

use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class BulkyDocumentExport implements FromCollection, WithHeadings
{
    protected $id;

    public function __construct(Request $request)
    {
        $this->id = $request->input('id');
    }

    public function collection()
    {
        $bulkyDocument = BulkyDocument::with(['buyer', 'bagProducts.bulkySales'])
            ->where('id', $this->id)
            ->first();

        $rows = [];

        // Data utama
        $rows[] = [
            $bulkyDocument->id,
            $bulkyDocument->name_document,
            $bulkyDocument->code_document_bulky,
            $bulkyDocument->name_user,
            $bulkyDocument->total_product_bulky,
            $bulkyDocument->total_old_price_bulky,
            $bulkyDocument->after_price_bulky,
            $bulkyDocument->buyer?->name_buyer,
            $bulkyDocument->status_bulky,
            $bulkyDocument->discount_bulky,
            $bulkyDocument->category_bulky,
        ];

        // Baris kosong (opsional)
        $rows[] = [];

        // Summary Bag
        $totalBag = $bulkyDocument->bagProducts->count();
        $totalOldPrice = 0;
        $totalAfterPrice = 0;

        foreach ($bulkyDocument->bagProducts as $bag) {
            foreach ($bag->bulkySales as $sale) {
                $totalOldPrice += $sale->old_price_bulky_sale;
                $totalAfterPrice += $sale->after_price_bulky_sale;
            }
        }
        $rows[] = ['Total Bag', $totalBag];
        $rows[] = [];
        $rows[] = ['No', 'Crew', 'Bag Name', 'Bag Barcode', 'Total Old Price Bag', 'Total After Price Bag', 'Total Barang Bag'];

        $i = 1;
        foreach ($bulkyDocument->bagProducts->sortBy('id') as $bag) {
            $oldPrice = $bag->bulkySales->sum('old_price_bulky_sale');
            $afterPrice = $bag->bulkySales->sum('after_price_bulky_sale');
            $rows[] = [
                $i,
                $bag->user->username,
                $bag->name_bag,
                $bag->barcode_bag,
                $oldPrice,
                $afterPrice,
                $bag->total_product
            ];
            $i++;
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name Document',
            'Code Document',
            'Name User',
            'Total Product Bulky',
            'Total Old Price',
            'After Price Bulky',
            'Buyer',
            'Status Bulky',
            'Discount',
            'Category',
        ];
    }
}
