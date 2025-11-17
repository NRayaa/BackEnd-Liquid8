<?php

namespace App\Exports;

use App\Models\Sale;
use App\Models\BulkySale;
use App\Models\New_product;
use App\Models\RepairProduct;
use App\Models\Product_Bundle;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Models\PaletProduct;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductSummaryInboundExport implements WithMultipleSheets
{
    use Exportable;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date;
    }

    public function sheets(): array
    {
        $sheets = [];
        
        // Sheet 1: Product Display (Inbound Products)
        $sheets[] = new ProductDisplaySheet($this->date);
        
        // Sheet 2: Product Outbound 
        $sheets[] = new ProductOutboundSheet($this->date);

        return $sheets;
    }
}

// Sheet untuk Product Display (Inbound Products)
class ProductDisplaySheet implements \Maatwebsite\Excel\Concerns\FromCollection, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
{
    use Exportable;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date;
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
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
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
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
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
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
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

// Sheet untuk Product Outbound
class ProductOutboundSheet implements \Maatwebsite\Excel\Concerns\FromCollection, WithHeadings, WithMapping, WithChunkReading, \Maatwebsite\Excel\Concerns\WithTitle
{
    use Exportable;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date;
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
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Product Bundle' as source_type")
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
            })
            ->get();
        
        // Get data from PaletProduct - struktur sama seperti product display
        $paletProducts = PaletProduct::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Palet Product' as source_type")
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
            })
            ->get();
        
        // Get data from RepairProduct - struktur sama seperti product display
        $repairProducts = RepairProduct::select(
                'new_barcode_product', 
                'old_barcode_product', 
                'new_price_product', 
                'old_price_product as actual_old_price_product', 
                'display_price', 
                'created_at', 
                'new_quantity_product'
            )
            ->selectRaw("'Repair Product' as source_type")
            ->when($this->date, function($query) {
                return $query->where('created_at', 'like', $this->date . '%');
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
            ->when($this->date, function($query) {
                return $query->where('actual_created_at', 'like', $this->date . '%');
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
            ->when($this->date, function($query) {
                return $query->where('actual_created_at', 'like', $this->date . '%');
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
