<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BagProductExport implements WithMultipleSheets
{
    use Exportable;

    protected $bagProduct;

    public function __construct($bagProduct)
    {
        $this->bagProduct = $bagProduct;
    }

    public function sheets(): array
    {

        $allSalesData = [];
        $grandTotalPrice = 0;

        foreach ($this->bagProduct as $bag) {
            if ($bag->bulkySales) {
                foreach ($bag->bulkySales as $sale) {
                    $price = $sale->old_price_bulky_sale ?? 0;
                    $grandTotalPrice += $price;

                    $allSalesData[] = [
                        'barcode'  => $sale->barcode_bulky_sale,
                        'name'     => $sale->name_product_bulky_sale,
                        'qty'      => $sale->qty,
                        'price'    => $price,
                        'category' => $sale->product_category_bulky_sale ?? 'Uncategorized',
                    ];
                }
            }
        }

        $sheets = [];


        $sheets[] = new BagProductListSheet($allSalesData, $grandTotalPrice);


        $sheets[] = new BagProductSummarySheet($allSalesData);



        $sheets[] = new BagProductPerBagSheet($this->bagProduct);

        return $sheets;
    }
}

class BagProductListSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $data;
    protected $grandTotalPrice;

    public function __construct($data, $grandTotalPrice)
    {
        $this->data = $data;
        $this->grandTotalPrice = $grandTotalPrice;
    }

    public function collection()
    {
        $output = collect();
        $totalRows = count($this->data);


        $output->push([$totalRows, '', '', $this->grandTotalPrice]);


        $output->push(['Barcode Bulky Sale', 'Name Product Bulky Sale', 'QTY', 'Old Price Bulky Sale']);


        foreach ($this->data as $row) {
            $output->push([$row['barcode'], $row['name'], $row['qty'], $row['price']]);
        }

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();


        $sheet->getStyle('A1:D1')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]
        ]);


        $sheet->getStyle('A2:D2')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        if ($lastRow > 2) {
            $sheet->getStyle("A3:D{$lastRow}")->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
            $sheet->getStyle("D1:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }

        return [];
    }

    public function title(): string
    {
        return 'List Products';
    }
}

class BagProductSummarySheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        $output = collect();
        $output->push([
            'CATEGORY',
            'Count of Barcode Bulky Sale',
            'Sum of Old Price Bulky Sale'
        ]);

        $grouped = collect($this->data)->groupBy('category');
        $totalCount = 0;
        $totalPrice = 0;

        foreach ($grouped as $category => $items) {
            $count = count($items);
            $sumPrice = collect($items)->sum('price');
            $totalCount += $count;
            $totalPrice += $sumPrice;
            $output->push([$category, $count, $sumPrice]);
        }

        $output->push(['Grand Total', $totalCount, $totalPrice]);
        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        $sheet->getStyle("A2:C{$lastRow}")->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
        $sheet->getStyle("A{$lastRow}:C{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']]
        ]);
        $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        return [];
    }

    public function title(): string
    {
        return 'Summary Category';
    }
}

class BagProductPerBagSheet implements FromCollection, ShouldAutoSize, WithStyles, WithTitle
{
    protected $bagProduct;

    public function __construct($bagProduct)
    {
        $this->bagProduct = $bagProduct;
    }

    public function collection()
    {
        $output = collect();


        $output->push([
            'NO',
            'Barcode',
            'Name Bag',
            'Count of Barcode Bulky Sale',
            'Sum of Old Price Bulky Sale'
        ]);


        $no = 1;
        $grandTotalCount = 0;
        $grandTotalPrice = 0;

        foreach ($this->bagProduct as $bag) {


            $items = $bag->bulkySales ?? collect([]);

            $countPerBag = $items->count();
            $sumPricePerBag = $items->sum('old_price_bulky_sale');


            $grandTotalCount += $countPerBag;
            $grandTotalPrice += $sumPricePerBag;

            $output->push([
                $no++,
                $bag->barcode_bag,
                $bag->name_bag,
                $countPerBag,
                $sumPricePerBag
            ]);
        }


        $output->push([
            'Grand Total',
            '',
            '',
            $grandTotalCount,
            $grandTotalPrice
        ]);

        return $output;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();


        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);


        $sheet->getStyle("A2:E{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $sheet->mergeCells("A{$lastRow}:C{$lastRow}");

        $sheet->getStyle("D{$lastRow}:E{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        $sheet->getStyle("A{$lastRow}:C{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBDD7EE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);


        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [];
    }

    public function title(): string
    {
        return 'Summary Bag';
    }
}
