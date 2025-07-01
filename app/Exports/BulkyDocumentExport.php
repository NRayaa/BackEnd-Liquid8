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
            $bulkyDocument->total_product_bulky,
            $bulkyDocument->total_old_price_bulky,
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
        $rows[] = ['No', 'Bag Name', 'Total Price', 'Total Barang Bag'];

        $i = 1;

        // Ambil semua sales dari semua bag yang terkait dokumen ini
        $allSales = $bulkyDocument->bagProducts->flatMap(function ($bag) {
            return $bag->bulkySales;
        });

        // Group by kategori dan hitung jumlahnya
        $categoryCounts = $allSales
            ->groupBy('product_category_bulky_sale')
            ->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => count($items),
                    'price' => $items->sum('old_price_bulky_sale')
                ];
            })
            ->values();


        foreach ($bulkyDocument->bagProducts->sortBy('id') as $bag) {
            $oldPrice = $bag->bulkySales->sum('old_price_bulky_sale');
            $rows[] = [
                $i,
                $bag->name_bag,
                $oldPrice,
                $bag->total_product
            ];

            // Summary kategori untuk sales di bag ini saja
            $categoryCounts = $bag->bulkySales
                ->groupBy('product_category_bulky_sale')
                ->map(function ($items, $category) {
                    return [
                        'category' => $category,
                        'count' => count($items),
                        'price' => $items->sum('old_price_bulky_sale')
                    ];
                })
                ->values();

            // Header kategori (opsional)
            $rows[] = ['','category', 'total', 'price'];

            foreach ($categoryCounts as $cat) {
                $rows[] = [
                    '', // Kolom A kosong
                    $cat['category'],
                    $cat['count'],
                    $cat['price']
                ];
            }

            // Baris kosong pemisah antar bag
            $rows[] = ['', '', '', '', '', '', ''];
            $i++;
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name Document',
            'Total Product Bulky',
            'Total Old Price',
        ];
    }
}
