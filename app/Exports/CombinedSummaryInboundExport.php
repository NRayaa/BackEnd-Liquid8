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
        
        // Get data from New_product
        $newProducts = New_product::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'actual_old_price_product', 
                'display_price', 
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'New Product' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from StagingProduct
        $stagingProducts = StagingProduct::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Staging Product' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from ProductApprove
        $productApproves = ProductApprove::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Product Approve' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
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
        
        // Get data from Product_Bundle - struktur sama seperti product display
        $productBundles = Product_Bundle::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'actual_created_at as created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Product Bundle' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from PaletProduct - struktur sama seperti product display
        $paletProducts = PaletProduct::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'actual_created_at as created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Palet Product' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from RepairProduct - struktur sama seperti product display
        $repairProducts = RepairProduct::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'actual_created_at as created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Repair Product' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from BulkySale - mapping khusus
        $bulkySales = BulkySale::select(
                'barcode_bulky_sale as new_barcode_product',
                'old_barcode_product',
                'after_price_bulky_sale as new_price_product',
                'actual_old_price_product',
                'display_price',
                'actual_created_at as created_at',
                'qty as new_quantity_product'
            )
            ->selectRaw("'Bulky Sale' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('actual_created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('actual_created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('actual_created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('actual_created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Get data from Sale - mapping khusus
        $sales = Sale::select(
                'product_barcode_sale as new_barcode_product',
                'old_barcode_product',
                'product_price_sale as new_price_product',
                'actual_product_old_price_sale as actual_old_price_product',
                'display_price',
                'actual_created_at as created_at',
                'product_qty_sale as new_quantity_product'
            )
            ->selectRaw("'Sale' as source_type")
            ->when($this->dateFrom && $this->dateTo, function($query) {
                return $query->whereBetween('actual_created_at', [
                    $this->dateFrom . ' 00:00:00', 
                    $this->dateTo . ' 23:59:59'
                ]);
            })
            ->when($this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('actual_created_at', 'like', $this->dateFrom . '%');
            })
            ->when(!$this->dateFrom && $this->dateTo, function($query) {
                return $query->where('actual_created_at', '<=', $this->dateTo . ' 23:59:59');
            })
            ->when(!$this->dateFrom && !$this->dateTo, function($query) {
                return $query->where('actual_created_at', 'like', Carbon::now('Asia/Jakarta')->toDateString() . '%');
            })
            ->get();
        
        // Gabungkan semua collection
        $collection = $collection->merge($productBundles)
                                ->merge($paletProducts)
                                ->merge($repairProducts)
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
