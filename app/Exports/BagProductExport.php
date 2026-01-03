<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class BagProductExport implements FromCollection, ShouldAutoSize, WithStyles
{
    protected $bagProduct;

    public function __construct($bagProduct)
    {
        $this->bagProduct = $bagProduct;
    }

    public function collection()
    {
        $output = collect();
        $allSalesData = [];

        $grandTotalPrice = 0;


        foreach ($this->bagProduct as $bag) {
            foreach ($bag->bulkySales as $sale) {

                $price = $sale->old_price_bulky_sale;
                $grandTotalPrice += $price;

                $allSalesData[] = [
                    'barcode' => $sale->barcode_bulky_sale,
                    'name' => $sale->name_product_bulky_sale,
                    'qty' => $sale->qty,
                    'price' => $price,
                    'category' => $sale->product_category_bulky_sale ?? 'Uncategorized',
                ];
            }
        }

        $totalProductRows = count($allSalesData);


        $output->push([
            $totalProductRows,
            '',
            '',
            $grandTotalPrice
        ]);


        $output->push([
            'Barcode Bulky Sale',
            'Name Product Bulky Sale',
            'QTY',
            'Old Price Bulky Sale'
        ]);


        foreach ($allSalesData as $data) {
            $output->push([
                $data['barcode'],
                $data['name'],
                $data['qty'],
                $data['price'],
            ]);
        }


        $output->push(['', '', '', '']);
        $output->push(['', '', '', '']);


        $output->push(['CATEGORY', 'Count of Barcode Bulky Sale', 'Sum of Old Price Bulky Sale', '']);


        $grouped = collect($allSalesData)->groupBy('category');

        $summaryTotalCount = 0;
        $summaryTotalPrice = 0;

        foreach ($grouped as $category => $items) {
            $count = count($items);
            $sumPrice = collect($items)->sum('price');

            $summaryTotalCount += $count;
            $summaryTotalPrice += $sumPrice;

            $output->push([
                $category,
                $count,
                $sumPrice,
                ''
            ]);
        }


        $output->push([
            'Grand Total',
            $summaryTotalCount,
            $summaryTotalPrice,
            ''
        ]);

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();


        $catHeaderRow = null;
        for ($row = $lastRow; $row > 1; $row--) {
            if ($sheet->getCell('A' . $row)->getValue() === 'CATEGORY') {
                $catHeaderRow = $row;
                break;
            }
        }


        $styles = [

            1 => [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'font' => ['bold' => true, 'size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]
            ],

            'C' => ['numberFormat' => ['formatCode' => '#,##0']],
            'D' => ['numberFormat' => ['formatCode' => '#,##0']],
        ];

        if ($catHeaderRow) {


            $endProductRow = $catHeaderRow - 3;
            if ($endProductRow >= 2) {
                $sheet->getStyle("A2:D{$endProductRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
                ]);
            }


            $styles[2] = [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
            ];



            $sheet->getStyle("A{$catHeaderRow}:D{$lastRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);


            $styles[$catHeaderRow] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
            ];


            $styles[$lastRow] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
            ];
        }

        return $styles;
    }
}
