<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Sale;
use App\Models\BulkySale;
use App\Models\New_product;
use App\Models\RepairProduct;
use App\Models\Product_Bundle;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Models\PaletProduct;
use App\Models\SummaryOutbound;
use App\Models\Document;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CombinedSummaryOutboundExport implements WithMultipleSheets
{
    use Exportable;

    protected $dateFrom;
    protected $dateTo;

    public function __construct($dateFrom = null, $dateTo = null)
    {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
    }

    public function sheets(): array
    {
        $sheets = [];


        $sheets[] = new ProductOutboundSheet($this->dateFrom, $this->dateTo);


        $sheets[] = new SummaryOutboundSheet($this->dateFrom, $this->dateTo);

        return $sheets;
    }
}


class ProductOutboundSheet implements \Maatwebsite\Excel\Concerns\FromCollection, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
{
    use Exportable;

    protected $dateFrom;
    protected $dateTo;

    public function __construct($dateFrom = null, $dateTo = null)
    {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
    }

    public function collection()
    {
        $collection = collect();


        $newProducts = New_product::select(
            'new_products.new_barcode_product',
            'new_products.old_barcode_product',
            'new_products.new_price_product',
            'new_products.actual_old_price_product',
            'new_products.display_price',
            'new_products.created_at',
            'new_products.new_quantity_product'
        )
            ->selectRaw("'Scrap/Damaged (Inventory)' as source_type")
            ->leftJoin('documents', 'new_products.code_document', '=', 'documents.code_document')

            ->where(function ($query) {
                $query->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('new_products.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('new_products.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('new_products.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('new_products.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();


        $stagingProducts = StagingProduct::select(
            'staging_products.new_barcode_product',
            'staging_products.old_barcode_product',
            'staging_products.new_price_product',
            'staging_products.old_price_product as actual_old_price_product',
            'staging_products.display_price',
            'staging_products.created_at',
            'staging_products.new_quantity_product'
        )
            ->selectRaw("'Scrap/Damaged (Staging)' as source_type")
            ->leftJoin('documents', 'staging_products.code_document', '=', 'documents.code_document')

            ->where(function ($query) {
                $query->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('staging_products.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('staging_products.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('staging_products.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('staging_products.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();


        $paletProducts = PaletProduct::select(
            'palet_products.new_barcode_product',
            'palet_products.old_barcode_product',
            'palet_products.new_price_product',
            'palet_products.old_price_product as actual_old_price_product',
            'palet_products.display_price',
            'palet_products.created_at as created_at',
            'palet_products.new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'palet_products.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('palet_products.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('palet_products.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('palet_products.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('palet_products.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();


        $bulkySales = BulkySale::select(
            'bulky_sales.barcode_bulky_sale as new_barcode_product',
            'bulky_sales.old_barcode_product',
            'bulky_sales.after_price_bulky_sale as new_price_product',
            'bulky_sales.actual_old_price_product',
            'bulky_sales.display_price',
            'bulky_sales.created_at as created_at',
            'bulky_sales.qty as new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'bulky_sales.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('bulky_sales.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('bulky_sales.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('bulky_sales.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('bulky_sales.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();


        $sales = Sale::select(
            'sales.product_barcode_sale as new_barcode_product',
            'sales.old_barcode_product',
            'sales.product_price_sale as new_price_product',
            'sales.actual_product_old_price_sale as actual_old_price_product',
            'sales.display_price',
            'sales.created_at as created_at',
            'sales.product_qty_sale as new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'sales.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('sales.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('sales.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('sales.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('sales.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();


        $collection = $collection->merge($newProducts)
            ->merge($stagingProducts)
            ->merge($paletProducts)
            ->merge($bulkySales)
            ->merge($sales);

        return $collection;
    }

    public function headings(): array
    {
        return [
            'Source Type',
            'New Barcode Product',
            'Old Barcode Product',
            'Price Sale',
            'Actual Old Price Product',
            'Display Price',
            'Created At',
            'New Quantity Product',
        ];
    }

    public function map($product): array
    {
        return [
            $product->source_type ?? 'Unknown',
            $product->new_barcode_product ?? null,
            $product->old_barcode_product ?? null,
            $product->new_price_product ?? null,
            $product->actual_old_price_product ?? null,
            $product->display_price ?? null,
            $product->created_at ? $product->created_at->format('Y-m-d H:i:s') : null,
            $product->new_quantity_product ?? null,
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'Product Outbound';
    }
}


class SummaryOutboundSheet implements \Maatwebsite\Excel\Concerns\FromQuery, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
{
    use Exportable;

    protected $dateFrom;
    protected $dateTo;

    public function __construct($dateFrom = null, $dateTo = null)
    {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
    }

    public function query()
    {
        $summaryOutbound = SummaryOutbound::latest();


        if ($this->dateFrom && $this->dateTo) {
            $summaryOutbound = $summaryOutbound->whereBetween('outbound_date', [$this->dateFrom, $this->dateTo]);
        } elseif ($this->dateFrom && !$this->dateTo) {
            $summaryOutbound = $summaryOutbound->where('outbound_date', $this->dateFrom);
        } elseif (!$this->dateFrom && $this->dateTo) {
            $summaryOutbound = $summaryOutbound->where('outbound_date', '<=', $this->dateTo);
        } else {
            $summaryOutbound = $summaryOutbound->where('outbound_date', Carbon::now('Asia/Jakarta')->toDateString());
        }

        return $summaryOutbound;
    }

    public function headings(): array
    {

        return [
            'ID',
            'Quantity',
            'Price Sale',
            'Old Price Product',
            'Display Price Product',
            'Discount',
            'Outbound Date',
            'Created At',
        ];
    }

    public function map($summary): array
    {

        return [
            $summary->id,
            $summary->qty,
            $summary->price_sale,
            $summary->old_price_product,
            $summary->display_price_product,
            $summary->discount,
            $summary->outbound_date,
            $summary->created_at ? $summary->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'Summary Outbound';
    }
}
