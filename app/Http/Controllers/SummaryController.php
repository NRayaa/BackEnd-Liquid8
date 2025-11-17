<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Sale;
use App\Models\BulkySale;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\RepairProduct;
use App\Models\Product_Bundle;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Models\SummaryInbound;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SummaryInboundExport;
use App\Exports\ProductSummaryInboundExport;
use App\Http\Resources\ResponseResource;
use App\Models\PaletProduct;

class SummaryController extends Controller
{
    public function summaryInbound(Request $request){
        $date = Carbon::now('Asia/Jakarta')->toDateString();
        
        // product display
        $getDataNp = New_product::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();
        
        $getDataSp = StagingProduct::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();

        $getDataPa = ProductApprove::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();

        // data product outbound
        
        $getDataPb = Product_Bundle::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();

        $getDataPa = PaletProduct::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();
        
        $getDataRp = RepairProduct::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(new_price_product), 0) as new_price_product,
            COALESCE(SUM(old_price_product), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('created_at', 'like', $date.'%')->first();
        
        $getDataBs = BulkySale::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(after_price_bulky_sale), 0) as new_price_product,
            COALESCE(SUM(old_price_bulky_sale), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('actual_created_at', 'like', $date.'%')->first();

        $getDataBs = Sale::selectRaw('
            COUNT(id) as qty,
            COALESCE(SUM(product_price_sale), 0) as new_price_product,
            COALESCE(SUM(product_old_price_sale), 0) as old_price_product,
            COALESCE(SUM(display_price), 0) as display_price
        ')->where('actual_created_at', 'like', $date.'%')->first();
        
        // Calculate totals
        $totalQty = ($getDataNp->qty ?? 0) + ($getDataSp->qty ?? 0) + ($getDataPb->qty ?? 0) + 
                   ($getDataPa->qty ?? 0) + ($getDataRp->qty ?? 0) + ($getDataBs->qty ?? 0);
        
        $totalNewPrice = ($getDataNp->new_price_product ?? 0) + ($getDataSp->new_price_product ?? 0) + 
                        ($getDataPb->new_price_product ?? 0) + ($getDataPa->new_price_product ?? 0) + 
                        ($getDataRp->new_price_product ?? 0) + ($getDataBs->new_price_product ?? 0);
        
        $totalOldPrice = ($getDataNp->old_price_product ?? 0) + ($getDataSp->old_price_product ?? 0) + 
                        ($getDataPb->old_price_product ?? 0) + ($getDataPa->old_price_product ?? 0) + 
                        ($getDataRp->old_price_product ?? 0) + ($getDataBs->old_price_product ?? 0);
        
        $totalDisplayPrice = ($getDataNp->display_price ?? 0) + ($getDataSp->display_price ?? 0) + 
                            ($getDataPb->display_price ?? 0) + ($getDataPa->display_price ?? 0) + 
                            ($getDataRp->display_price ?? 0) + ($getDataBs->display_price ?? 0);
        
        SummaryInbound::updateOrCreate(
            ['inbound_date' => $date],
            [
                'qty' => $totalQty,
                'new_price_product' => $totalNewPrice,
                'old_price_product' => $totalOldPrice,
                'display_price' => $totalDisplayPrice,
            ]
        );

    }

    public function exportProductSummaryInbound(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            // Validate and get date from request
            $date = $request->input('date');
            
            // If date is provided, validate format, otherwise use today's date
            if ($date) {
                // Validate date format (Y-m-d)
                if (!Carbon::hasFormat($date, 'Y-m-d')) {
                    return new ResponseResource(false, "Format tanggal harus Y-m-d (contoh: 2025-11-17)", []);
                }
                // Parse and format the date to ensure it's valid (Y-m-d format)
                $date = Carbon::parse($date)->toDateString();
            } else {
                // Default to today's date in Y-m-d format (tahun-bulan-hari)
                $date = Carbon::now('Asia/Jakarta')->toDateString();
            }
            
            // Filename follows the date format: product_summary_inbound_YYYY-MM-DD.xlsx
            $fileName = 'product_summary_inbound_' . $date . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductSummaryInboundExport($date), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File produk berhasil diunduh untuk tanggal: " . $date, $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportSummaryInbound(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            // Validate and get date from request
            $date = $request->input('date');
            
            // If date is provided, validate format, otherwise use today's date
            if ($date) {
                // Validate date format (Y-m-d)
                if (!Carbon::hasFormat($date, 'Y-m-d')) {
                    return new ResponseResource(false, "Format tanggal harus Y-m-d (contoh: 2025-11-17)", []);
                }
                // Parse and format the date to ensure it's valid (Y-m-d format)
                $date = Carbon::parse($date)->toDateString();
            } else {
                // Default to today's date in Y-m-d format (tahun-bulan-hari)
                $date = Carbon::now('Asia/Jakarta')->toDateString();
            }
            
            // Filename follows the date format: inbound_summary_YYYY-MM-DD.xlsx
            $fileName = 'inbound_summary_' . $date . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new SummaryInboundExport($date), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh untuk tanggal: " . $date, $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function listSummaryInbound(Request $request)
    {
        $date = $request->input('date');
        $summaryInbound = SummaryInbound::query();

        if ($date) {
            $summaryInbound->where(function ($query) use ($date) {
                $query->where('inbound_date', 'like', "%{$date}%");
            });
        }else{
            $summaryInbound->where(function ($query) {
                $query->where('inbound_date', Carbon::now('Asia/Jakarta')->toDateString());
            });
        }

        $summaryInbound = $summaryInbound->paginate(110);

        return new ResponseResource(true, "List of summary inbound", $summaryInbound);
    }
}
