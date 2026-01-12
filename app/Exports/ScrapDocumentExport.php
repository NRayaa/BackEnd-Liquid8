<?php

namespace App\Exports;

use App\Models\ScrapDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\MigrateBulkyProduct;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ScrapDocumentExport implements WithMultipleSheets
{
    use Exportable;

    protected $scrapDocumentId;

    public function __construct($scrapDocumentId)
    {
        $this->scrapDocumentId = $scrapDocumentId;
    }

    public function sheets(): array
    {
        return [
            'All Products' => new ScrapProductListSheet($this->scrapDocumentId),
            'Documents Summary' => new ScrapSummarySheet($this->scrapDocumentId),
        ];
    }
}

class ScrapProductListSheet implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $scrapDocumentId;

    public function __construct($scrapDocumentId)
    {
        $this->scrapDocumentId = $scrapDocumentId;
    }

    public function title(): string
    {
        return 'Product List';
    }

    public function query()
    {
        $commonColumns = [
            'id', 'code_document', 'old_barcode_product', 'new_barcode_product',
            'new_name_product', 'new_quantity_product', 'new_price_product',
            'old_price_product', 'new_date_in_product', 'new_status_product',
            'new_quality', 'new_category_product', 'created_at'
        ];

        $displayQuery = New_product::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                'new_discount',
                'display_price',
                DB::raw("'Display' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        $stagingQuery = StagingProduct::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Staging' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        $migrateQuery = MigrateBulkyProduct::select($commonColumns)
            ->addSelect([
                DB::raw("NULL as new_tag_product"),
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Migrate' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        return $displayQuery->unionAll($stagingQuery)->unionAll($migrateQuery)->orderBy('new_category_product', 'asc');
    }

    public function headings(): array
    {
        return [
            'Source', 'Code Document', 'Old Barcode Product', 'New Barcode Product',
            'Name Product', 'New Category Product', 'New Qty Product',
            'Old Price Product', 'New Price Product', 'Date In', 'Status',
            'Quality Description', 'Tag', 'Discount', 'Scrap Date'
        ];
    }

    public function map($row): array
    {
        $qualityDescription = '-';
        if (!empty($row->new_quality)) {
            $qualityData = is_string($row->new_quality) ? json_decode($row->new_quality, true) : (array) $row->new_quality;
            if (is_array($qualityData)) {
                if (!empty($qualityData['migrate'])) $qualityDescription = $qualityData['migrate'];
                elseif (!empty($qualityData['damaged'])) $qualityDescription = $qualityData['damaged'];
                elseif (!empty($qualityData['abnormal'])) $qualityDescription = $qualityData['abnormal'];
                elseif (!empty($qualityData['lolos'])) $qualityDescription = 'Lolos';
            }
        }

        return [
            $row->source_storage,
            $row->code_document,
            " " . $row->old_barcode_product,
            " " . $row->new_barcode_product,
            $row->new_name_product,
            $row->new_category_product,
            $row->new_quantity_product,
            $row->old_price_product,
            $row->new_price_product,
            $row->new_date_in_product,
            $row->new_status_product,
            $qualityDescription,
            $row->new_tag_product,
            $row->new_discount,
            $row->created_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => '4472C4']],
            ],
        ];
    }
}

class ScrapSummarySheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $scrapDocumentId;

    public function __construct($scrapDocumentId)
    {
        $this->scrapDocumentId = $scrapDocumentId;
    }

    public function title(): string
    {
        return 'Documents Summary';
    }

    public function collection()
    {
        return ScrapDocument::with('user:id,name')
            ->where('id', $this->scrapDocumentId)
            ->get();
    }

    public function headings(): array
    {
        return [
            'Code Document Scrap',
            'User Name',
            'Total Product',
            'Total New Price',
            'Total Old Price',
            'Document Status',
            'Created At',
            'Updated At',
        ];
    }

    public function map($doc): array
    {
        return [
            $doc->code_document_scrap,
            $doc->user->name ?? 'N/A',
            $doc->total_product,
            $doc->total_new_price,
            $doc->total_old_price,
            $doc->status,
            $doc->created_at->format('Y-m-d H:i'),
            $doc->updated_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();
        $lastColumn = $sheet->getHighestColumn();
        $range = 'A1:' . $lastColumn . $lastRow;

        return [
            $range => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => '4472C4'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            'A2:B2' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]],
        ];
    }
}