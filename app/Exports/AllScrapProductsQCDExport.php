<?php

namespace App\Exports;

use App\Models\ScrapDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\MigrateBulkyProduct;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Exportable;

class AllScrapProductsQCDExport implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            'All Products' => new AllScrapProductsSheet(),
            'Documents Summary' => new AllScrapDocumentsSummarySheet(),
        ];
    }
}

class AllScrapProductsSheet implements \Maatwebsite\Excel\Concerns\FromQuery, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithMapping, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithStyles
{
    protected $documentStatuses = [];

    public function __construct()
    {
        // Pre-load all document statuses
        $this->loadDocumentStatuses();
    }

    private function loadDocumentStatuses()
    {
        $displayDocs = New_product::whereHas('scrapDocuments')
            ->with('scrapDocuments:id,status')
            ->get()
            ->flatMap(function ($product) {
                return $product->scrapDocuments->map(function ($doc) use ($product) {
                    return ['product_id' => $product->id, 'model' => 'display', 'status' => $doc->status];
                });
            });

        $stagingDocs = StagingProduct::whereHas('scrapDocuments')
            ->with('scrapDocuments:id,status')
            ->get()
            ->flatMap(function ($product) {
                return $product->scrapDocuments->map(function ($doc) use ($product) {
                    return ['product_id' => $product->id, 'model' => 'staging', 'status' => $doc->status];
                });
            });

        $migrateDocs = MigrateBulkyProduct::whereHas('scrapDocuments')
            ->with('scrapDocuments:id,status')
            ->get()
            ->flatMap(function ($product) {
                return $product->scrapDocuments->map(function ($doc) use ($product) {
                    return ['product_id' => $product->id, 'model' => 'migrate', 'status' => $doc->status];
                });
            });

        foreach ($displayDocs as $item) {
            $this->documentStatuses['display_' . $item['product_id']] = $item['status'];
        }
        foreach ($stagingDocs as $item) {
            $this->documentStatuses['staging_' . $item['product_id']] = $item['status'];
        }
        foreach ($migrateDocs as $item) {
            $this->documentStatuses['migrate_' . $item['product_id']] = $item['status'];
        }
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
            'new_quality',
            'new_category_product',
            'created_at'
        ];

        $displayQuery = New_product::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                'new_discount',
                'display_price',
                DB::raw("'Display' as source_storage")
            ])
            ->whereHas('scrapDocuments'); // Include all statuses

        $stagingQuery = StagingProduct::select($commonColumns)
            ->addSelect([
                'new_tag_product',
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Staging' as source_storage")
            ])
            ->whereHas('scrapDocuments'); // Include all statuses

        $migrateQuery = MigrateBulkyProduct::select($commonColumns)
            ->addSelect([
                DB::raw("NULL as new_tag_product"),
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Migrate' as source_storage")
            ])
            ->whereHas('scrapDocuments'); // Include all statuses

        return $displayQuery->unionAll($stagingQuery)->unionAll($migrateQuery)->orderBy('new_category_product', 'asc');
    }

    public function headings(): array
    {
        return [
            [
                'Source',
                'Document Status',
                'Code Document',
                'Old Barcode Product',
                'New Barcode Product',
                'Name Product',
                'New Category Product',
                'New Qty Product',
                'Old Price Product',
                'New Price Product',
                'Date In',
                'Description',
                'Tag',
                'Discount',
                'Scrap Date'
            ]
        ];
    }

    public function map($row): array
    {
        // Get the scrap document status from pre-loaded data
        $modelKey = strtolower(str_replace('source_storage', '', $row->source_storage));
        if ($row->source_storage === 'Display') $modelKey = 'display';
        elseif ($row->source_storage === 'Staging') $modelKey = 'staging';
        elseif ($row->source_storage === 'Migrate') $modelKey = 'migrate';

        $cacheKey = $modelKey . '_' . $row->id;
        $scrapDocStatus = $this->documentStatuses[$cacheKey] ?? 'N/A';

        $qualityDescription = '-';

        if (!empty($row->new_quality)) {
            $qualityData = is_string($row->new_quality)
                ? json_decode($row->new_quality, true)
                : (array) $row->new_quality;

            if (is_array($qualityData)) {
                if (!empty($qualityData['migrate'])) {
                    $qualityDescription = $qualityData['migrate'];
                } elseif (!empty($qualityData['damaged'])) {
                    $qualityDescription = $qualityData['damaged'];
                } elseif (!empty($qualityData['abnormal'])) {
                    $qualityDescription = $qualityData['abnormal'];
                } elseif (!empty($qualityData['lolos'])) {
                    $qualityDescription = 'Lolos';
                }
            }
        }


        return [
            $row->source_storage,
            $scrapDocStatus,
            $row->code_document,
            " " . $row->old_barcode_product,
            " " . $row->new_barcode_product,
            $row->new_name_product,
            $row->new_category_product,
            $row->new_quantity_product,
            $row->old_price_product,
            $row->new_price_product,
            $row->new_date_in_product,
            $qualityDescription,
            $row->new_tag_product,
            $row->new_discount,
            $row->created_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => '4472C4']],
            ],
        ];
    }
}

class AllScrapDocumentsSummarySheet implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithStyles
{
    public function collection()
    {
        return ScrapDocument::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'code_document_scrap' => $doc->code_document_scrap,
                    'user_name' => $doc->user->name ?? 'N/A',
                    'total_product' => $doc->total_product,
                    'total_new_price' => $doc->total_new_price,
                    'total_old_price' => $doc->total_old_price,
                    'document_status' => $doc->status,
                    'created_at' => $doc->created_at->format('Y-m-d H:i'),
                    'updated_at' => $doc->updated_at->format('Y-m-d H:i'),
                ];
            });
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

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => '4472C4']],
            ],
        ];
    }
}
