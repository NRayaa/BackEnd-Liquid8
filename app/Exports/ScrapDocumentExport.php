<?php

namespace App\Exports;

use App\Models\ScrapDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\MigrateBulkyProduct;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScrapDocumentExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    use Exportable;

    protected $scrapDocumentId;
    protected $document;

    public function __construct($scrapDocumentId)
    {
        $this->scrapDocumentId = $scrapDocumentId;


        $this->document = ScrapDocument::find($scrapDocumentId);
    }


    public function query()
    {

        $commonColumns = [
            'id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'created_at'
        ];

        $displayQuery = New_product::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                'new_discount',
                'display_price',
                // DB::raw("'Display' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        $stagingQuery = StagingProduct::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                // DB::raw("'Staging' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        $migrateQuery = MigrateBulkyProduct::select($commonColumns)
            ->addSelect([
                DB::raw("NULL as new_tag_product"),
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                // DB::raw("'Migrate' as source_storage")
            ])
            ->whereHas('scrapDocuments', function ($q) {
                $q->where('scrap_document_id', $this->scrapDocumentId);
            });

        return $displayQuery->unionAll($stagingQuery)->unionAll($migrateQuery)->orderBy('new_category_product', 'asc');
    }

    public function headings(): array
    {
        $doc = $this->document;

        return [
            ['Total Product', $doc->total_product . ' pcs'],
            ['Total New Price', 'Rp ' . number_format($doc->total_new_price, 0, ',', '.')],
            ['Total Old Price', 'Rp ' . number_format($doc->total_old_price, 0, ',', '.')],
            [''],
            [
                // 'Source',
                'Code Document',
                'Old Barcode Product',
                'New Barcode Product',
                'Name Product',
                'New Category Product',
                'New Qty Product',
                'Old Price Product',
                'New Price Product',
                'Date In',
                'Status',
                'Quality',
                'Tag',
                'Discount',
                // 'Display Price',
                'Scrap Date'
            ]
        ];
    }

    public function map($row): array
    {
        return [
            // $row->source_storage,
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
            $row->new_quality,
            $row->new_tag_product,
            $row->new_discount,
            // $row->display_price,
            $row->created_at->format('Y-m-d H:i'),
        ];
    }


    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
            3 => ['font' => ['bold' => true]],
            5 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => '4472C4']],
            ],
        ];
    }
}
