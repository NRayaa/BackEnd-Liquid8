<?php

namespace App\Exports;

use App\Models\SkuProductOld;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class SkuProductOldExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithColumnFormatting
{
    protected $codeDocument;
    private $rowNumber = 0;

    public function __construct($codeDocument)
    {
        $this->codeDocument = $codeDocument;
    }

    public function collection()
    {
        return SkuProductOld::where('code_document', $this->codeDocument)->get();
    }


    public function headings(): array
    {
        return [
            'No',
            'Code Document',
            'Barcode',
            'Product Name',
            'Price',
            'QTY Awal',
            'QTY Aktual',
            'QTY Damaged',
            'QTY Lost',
        ];
    }

    public function map($product): array
    {
        $this->rowNumber++;
        
        return [
            $this->rowNumber,
            $product->code_document,
            (string) $product->old_barcode_product, 
            $product->old_name_product,
            $product->old_price_product,
            $product->old_quantity_product,
            $product->actual_quantity_product,
            $product->damaged_quantity_product,
            $product->lost_quantity_product,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT,
            'E' => '#,##0.00', 
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE0E0E0'],
            ],
        ]);

        $sheet->getStyle('A1:I' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        return [];
    }
}