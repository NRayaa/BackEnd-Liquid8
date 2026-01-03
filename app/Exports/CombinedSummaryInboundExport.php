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
use App\Models\SummaryInbound;
use App\Models\Document;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CombinedSummaryInboundExport implements WithMultipleSheets
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

        // Sheet 1: Product Display (Inbound Products)
        $sheets[] = new ProductDisplaySheet($this->dateFrom, $this->dateTo);

        // Sheet 2: Product Outbound 
        // $sheets[] = new ProductOutboundSheet($this->dateFrom, $this->dateTo);

        // Sheet 3: Summary Inbound (dari SummaryInboundExport)
        $sheets[] = new SummaryInboundSheet($this->dateFrom, $this->dateTo);

        return $sheets;
    }
}

// Sheet untuk Product Display (Inbound Products) - sama seperti sebelumnya
class ProductDisplaySheet implements \Maatwebsite\Excel\Concerns\FromCollection, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
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

        // Get data from New_product with leftJoin to documents
        $newProducts = New_product::select(
            'new_products.new_barcode_product',
            'new_products.old_barcode_product',
            'new_products.new_price_product',
            'new_products.actual_old_price_product',
            'new_products.display_price',
            'new_products.created_at',
            'new_products.new_quantity_product'
        )
            ->where('new_status_product', '!=', 'scrap_qcd')
            ->whereNull('new_quality->damaged')
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'new_products.code_document', '=', 'documents.code_document')
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

        // Get data from StagingProduct with leftJoin to documents
        $stagingProducts = StagingProduct::select(
            'staging_products.new_barcode_product',
            'staging_products.old_barcode_product',
            'staging_products.new_price_product',
            'staging_products.old_price_product as actual_old_price_product',
            'staging_products.display_price',
            'staging_products.created_at',
            'staging_products.new_quantity_product'
        )
            ->whereNull('new_quality->damaged')
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'staging_products.code_document', '=', 'documents.code_document')
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

        // Get data from ProductApprove with leftJoin to documents
        $productApproves = ProductApprove::select(
            'product_approves.new_barcode_product',
            'product_approves.old_barcode_product',
            'product_approves.new_price_product',
            'product_approves.old_price_product as actual_old_price_product',
            'product_approves.display_price',
            'product_approves.created_at',
            'product_approves.new_quantity_product'
        )
            ->whereNull('new_quality->damaged')
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'product_approves.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('product_approves.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('product_approves.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('product_approves.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('product_approves.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();

        // Gabungkan semua collection
        $collection = $collection->merge($newProducts)
            ->merge($stagingProducts)
            ->merge($productApproves);

        return $collection;
    }

    public function headings(): array
    {
        return [
            'Source Type',
            'New Barcode Product',
            'Old Barcode Product',
            'New Price Product',
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
        return 'Product Display';
    }
}

// Sheet untuk Product Outbound - sama seperti sebelumnya
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

        // Get data from Product_Bundle with leftJoin to documents
        // $productBundles = Product_Bundle::select(
        //         'product_bundles.new_barcode_product', 
        //         'product_bundles.old_barcode_product', 
        //         'product_bundles.new_price_product', 
        //         'product_bundles.old_price_product as actual_old_price_product', 
        //         'product_bundles.display_price', 
        //         'product_bundles.actual_created_at as created_at', 
        //         'product_bundles.new_quantity_product'
        //     )
        //     ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
        //     ->leftJoin('documents', 'product_bundles.code_document', '=', 'documents.code_document')
        //     ->when($this->dateFrom && $this->dateTo, function($query) {
        //         return $query->whereBetween('product_bundles.created_at', [
        //             $this->dateFrom . ' 00:00:00', 
        //             $this->dateTo . ' 23:59:59'
        //         ]);
        //     })
        //     ->when($this->dateFrom && !$this->dateTo, function($query) {
        //         return $query->where('product_bundles.created_at', 'like', $this->dateFrom . '%');
        //     })
        //     ->when(!$this->dateFrom && $this->dateTo, function($query) {
        //         return $query->where('product_bundles.created_at', '<=', $this->dateTo . ' 23:59:59');
        //     })
        //     ->when(!$this->dateFrom && !$this->dateTo, function($query) {
        //         return $query->where('product_bundles.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
        //     })
        //     ->get();

        // Get data from PaletProduct with leftJoin to documents
        $paletProducts = PaletProduct::select(
            'pallet_products.new_barcode_product',
            'pallet_products.old_barcode_product',
            'pallet_products.new_price_product',
            'pallet_products.old_price_product as actual_old_price_product',
            'pallet_products.display_price',
            'pallet_products.actual_created_at as created_at',
            'pallet_products.new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'pallet_products.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('pallet_products.created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('pallet_products.created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('pallet_products.created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('pallet_products.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();

        // Get data from RepairProduct with leftJoin to documents
        // $repairProducts = RepairProduct::select(
        //         'repair_products.new_barcode_product', 
        //         'repair_products.old_barcode_product', 
        //         'repair_products.new_price_product', 
        //         'repair_products.old_price_product as actual_old_price_product', 
        //         'repair_products.display_price', 
        //         'repair_products.actual_created_at as created_at', 
        //         'repair_products.new_quantity_product'
        //     )
        //     ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
        //     ->leftJoin('documents', 'repair_products.code_document', '=', 'documents.code_document')
        //     ->when($this->dateFrom && $this->dateTo, function($query) {
        //         return $query->whereBetween('repair_products.created_at', [
        //             $this->dateFrom . ' 00:00:00', 
        //             $this->dateTo . ' 23:59:59'
        //         ]);
        //     })
        //     ->when($this->dateFrom && !$this->dateTo, function($query) {
        //         return $query->where('repair_products.created_at', 'like', $this->dateFrom . '%');
        //     })
        //     ->when(!$this->dateFrom && $this->dateTo, function($query) {
        //         return $query->where('repair_products.created_at', '<=', $this->dateTo . ' 23:59:59');
        //     })
        //     ->when(!$this->dateFrom && !$this->dateTo, function($query) {
        //         return $query->where('repair_products.created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
        //     })
        //     ->get();

        // Get data from BulkySale with leftJoin to documents
        $bulkySales = BulkySale::select(
            'bulky_sales.barcode_bulky_sale as new_barcode_product',
            'bulky_sales.old_barcode_product',
            'bulky_sales.after_price_bulky_sale as new_price_product',
            'bulky_sales.actual_old_price_product',
            'bulky_sales.display_price',
            'bulky_sales.actual_created_at as created_at',
            'bulky_sales.qty as new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'bulky_sales.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('bulky_sales.actual_created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('bulky_sales.actual_created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('bulky_sales.actual_created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('bulky_sales.actual_created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();

        // Get data from Sale with leftJoin to documents
        $sales = Sale::select(
            'sales.product_barcode_sale as new_barcode_product',
            'sales.old_barcode_product',
            'sales.product_price_sale as new_price_product',
            'sales.actual_product_old_price_sale as actual_old_price_product',
            'sales.display_price',
            'sales.actual_created_at as created_at',
            'sales.product_qty_sale as new_quantity_product'
        )
            ->selectRaw("COALESCE(documents.base_document, 'Manual Inbound') as source_type")
            ->leftJoin('documents', 'sales.code_document', '=', 'documents.code_document')
            ->when($this->dateFrom && $this->dateTo, function ($query) {
                return $query->whereBetween('sales.actual_created_at', [
                    $this->dateFrom . ' 00:00:00',
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('sales.actual_created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function ($query) {
                return $query->where('sales.actual_created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function ($query) {
                return $query->where('sales.actual_created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();

        // Gabungkan semua collection
        $collection = $collection->merge($paletProducts)
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
            'New Price Product',
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

// Sheet untuk Summary Inbound - dari SummaryInboundExport
class SummaryInboundSheet implements \Maatwebsite\Excel\Concerns\FromQuery, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
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
        $summaryInbound = SummaryInbound::latest();

        // Filter logic berdasarkan dateFrom dan dateTo
        if ($this->dateFrom && $this->dateTo) {
            // Jika keduanya ada: filter range
            $summaryInbound = $summaryInbound->whereBetween('inbound_date', [$this->dateFrom, $this->dateTo]);
        } elseif ($this->dateFrom && !$this->dateTo) {
            // Jika hanya dateFrom: filter untuk tanggal itu saja
            $summaryInbound = $summaryInbound->where('inbound_date', $this->dateFrom);
        } elseif (!$this->dateFrom && $this->dateTo) {
            // Jika hanya dateTo: filter dari awal sampai dateTo
            $summaryInbound = $summaryInbound->where('inbound_date', '<=', $this->dateTo);
        } else {
            // Default ke hari ini jika tidak ada filter
            $summaryInbound = $summaryInbound->where('inbound_date', Carbon::now('Asia/Jakarta')->toDateString());
        }

        return $summaryInbound;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Quantity',
            'New Price Product',
            'Old Price Product',
            'Display Price',
            'Inbound Date',
            'Created At',
        ];
    }

    public function map($summary): array
    {
        return [
            $summary->id,
            $summary->qty,
            $summary->new_price_product,
            $summary->old_price_product,
            $summary->display_price,
            $summary->inbound_date,
            $summary->created_at ? $summary->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Chunk size per read operation
     */
    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'Summary Inbound';
    }
}
