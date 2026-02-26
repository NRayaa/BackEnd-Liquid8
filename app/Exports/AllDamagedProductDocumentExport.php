<?php

namespace App\Exports;

use App\Models\DamagedDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\MigrateBulkyProduct;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Exportable;

class AllDamagedProductDocumentExport implements WithMultipleSheets
{
    use Exportable;

    public function sheets(): array
    {
        return [
            'All Products' => new AllDamagedProductsSheet(),
            'Documents Summary' => new AllDamagedDocumentsSummarySheet(),
        ];
    }
}

class AllDamagedProductsSheet implements \Maatwebsite\Excel\Concerns\FromQuery, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithMapping, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithStyles
{
    protected $documentStatuses = [];

    public function __construct()
    {
        $this->loadDocumentStatuses();
    }

    private function loadDocumentStatuses()
    {

        // 1. Display (New Product)
        $displayStatuses = DB::table('damaged_document_items')
            ->join('damaged_documents', 'damaged_document_items.damaged_document_id', '=', 'damaged_documents.id')
            ->where('productable_type', New_product::class)
            ->select('productable_id', 'damaged_documents.status')
            ->get();

        foreach ($displayStatuses as $item) {
            $this->documentStatuses['display_' . $item->productable_id] = $item->status;
        }

        // 2. Staging
        $stagingStatuses = DB::table('damaged_document_items')
            ->join('damaged_documents', 'damaged_document_items.damaged_document_id', '=', 'damaged_documents.id')
            ->where('productable_type', StagingProduct::class)
            ->select('productable_id', 'damaged_documents.status')
            ->get();

        foreach ($stagingStatuses as $item) {
            $this->documentStatuses['staging_' . $item->productable_id] = $item->status;
        }

        // 3. Migrate
        $migrateStatuses = DB::table('damaged_document_items')
            ->join('damaged_documents', 'damaged_document_items.damaged_document_id', '=', 'damaged_documents.id')
            ->where('productable_type', MigrateBulkyProduct::class)
            ->select('productable_id', 'damaged_documents.status')
            ->get();

        foreach ($migrateStatuses as $item) {
            $this->documentStatuses['migrate_' . $item->productable_id] = $item->status;
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
            'created_at',
            'new_tag_product',
            'new_discount',
            'display_price'
        ];

        // Query Display
        $displayQuery = New_product::select($commonColumns)
            ->addSelect(DB::raw("'Display' as source_storage"))
            ->whereHas('damagedDocuments');

        $stagingQuery = StagingProduct::select([
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
            'created_at',
            'new_tag_product'
        ])
            ->addSelect([
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Staging' as source_storage")
            ])
            ->whereHas('damagedDocuments');

        // Query Migrate
        $migrateQuery = MigrateBulkyProduct::select([
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
        ])
            ->addSelect([
                DB::raw("NULL as new_tag_product"),
                DB::raw("0 as new_discount"),
                DB::raw("0 as display_price"),
                DB::raw("'Migrate' as source_storage")
            ])
            ->whereHas('damagedDocuments');

        return $displayQuery->unionAll($stagingQuery)->unionAll($migrateQuery)->orderBy('new_category_product', 'asc');
    }

    public function headings(): array
    {
        return [
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
            'Damaged Date'
        ];
    }

    public function map($row): array
    {
        $modelKey = strtolower($row->source_storage);
        $cacheKey = $modelKey . '_' . $row->id;
        $damagedDocStatus = $this->documentStatuses[$cacheKey] ?? 'N/A';

        $qualityDescription = '-';
        if (!empty($row->new_quality)) {
            $qualityData = is_string($row->new_quality) ? json_decode($row->new_quality, true) : (array) $row->new_quality;
            if (is_array($qualityData)) {
                if (!empty($qualityData['damaged'])) $qualityDescription = $qualityData['damaged'];
                elseif (!empty($qualityData['abnormal'])) $qualityDescription = $qualityData['abnormal'];
                elseif (!empty($qualityData['non'])) $qualityDescription = $qualityData['non'];
                elseif (!empty($qualityData['migrate'])) $qualityDescription = $qualityData['migrate'];
                elseif (!empty($qualityData['lolos'])) $qualityDescription = 'Lolos';
            }
        }

        return [
            $row->source_storage,
            $damagedDocStatus,
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

class AllDamagedDocumentsSummarySheet implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\ShouldAutoSize, \Maatwebsite\Excel\Concerns\WithStyles
{
    public function collection()
    {
        return DamagedDocument::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'code_document_damaged' => $doc->code_document_damaged,
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
            'Code Document Damaged',
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
