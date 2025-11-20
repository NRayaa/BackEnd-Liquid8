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
use App\Exports\CombinedSummaryInboundExport;
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

    /**
     * Export gabungan dari product summary inbound dan summary inbound dalam satu file Excel dengan 3 sheet
     */
    public function exportCombinedSummaryInbound(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $currentDate = Carbon::now('Asia/Jakarta');
            
            // Validate date formats if provided
            if ($dateFrom && !Carbon::hasFormat($dateFrom, 'Y-m-d')) {
                return new ResponseResource(false, "Format date_from harus Y-m-d (contoh: 2025-11-17)", []);
            }
            if ($dateTo && !Carbon::hasFormat($dateTo, 'Y-m-d')) {
                return new ResponseResource(false, "Format date_to harus Y-m-d (contoh: 2025-11-17)", []);
            }

            // Validasi berdasarkan data summary inbound yang ada
            $firstSummaryInbound = SummaryInbound::orderBy('inbound_date', 'asc')->first();
            $lastSummaryInbound = SummaryInbound::orderBy('inbound_date', 'desc')->first();
            
            // Validasi date_from tidak boleh kurang dari tanggal data pertama
            if($dateFrom && $firstSummaryInbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryInbound->inbound_date))){
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_from tidak boleh kurang dari tanggal data pertama summary inbound yaitu ".$firstSummaryInbound->inbound_date,
                        'resource' => []
                    ]
                ], 422);
            }
            
            // Validasi date_to tidak boleh lebih dari tanggal data terakhir
            if($dateTo && $lastSummaryInbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryInbound->inbound_date))){
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_to tidak boleh lebih dari tanggal data terakhir summary inbound yaitu ".$lastSummaryInbound->inbound_date,
                        'resource' => []
                    ]
                ], 422);
            }
            
            // Validasi date_from harus <= date_to
            if($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))){
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_from tidak boleh lebih besar dari date_to",
                        'resource' => []
                    ]
                ], 422);
            }
            
            // Determine filename based on date range
            $fileNamePart = '';
            if ($dateFrom && $dateTo) {
                $fileNamePart = $dateFrom . '_to_' . $dateTo;
            } elseif ($dateFrom) {
                $fileNamePart = $dateFrom;
            } elseif ($dateTo) {
                $fileNamePart = 'until_' . $dateTo;
            } else {
                $fileNamePart = $currentDate->toDateString();
            }
            
            // Filename follows the date format
            $fileName = 'combined_summary_inbound_' . $fileNamePart . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new CombinedSummaryInboundExport($dateFrom, $dateTo), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            $message = "File gabungan berhasil diunduh";
            if ($dateFrom && $dateTo) {
                $message .= " untuk periode: " . $dateFrom . " sampai " . $dateTo;
            } elseif ($dateFrom) {
                $message .= " untuk tanggal: " . $dateFrom;
            } elseif ($dateTo) {
                $message .= " sampai tanggal: " . $dateTo;
            } else {
                $message .= " untuk tanggal: " . $currentDate->toDateString();
            }

            return new ResponseResource(true, $message, $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file gabungan: " . $e->getMessage(), []);
        }
    }

    public function listSummaryInbound(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $currentDate = Carbon::now('Asia/Jakarta');
        
        // Validasi format tanggal jika ada
        if ($dateFrom && !Carbon::hasFormat($dateFrom, 'Y-m-d')) {
            return new ResponseResource(false, "Format date_from harus Y-m-d (contoh: 2025-11-17)", []);
        }
        if ($dateTo && !Carbon::hasFormat($dateTo, 'Y-m-d')) {
            return new ResponseResource(false, "Format date_to harus Y-m-d (contoh: 2025-11-17)", []);
        }
        
        // Validasi berdasarkan data summary inbound yang ada
        $firstSummaryInbound = SummaryInbound::orderBy('inbound_date', 'asc')->first();
        $lastSummaryInbound = SummaryInbound::orderBy('inbound_date', 'desc')->first();
        
        // Validasi date_from tidak boleh kurang dari tanggal data pertama
        if($dateFrom && $firstSummaryInbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryInbound->inbound_date))){
            return new ResponseResource(false, "date_from tidak boleh kurang dari tanggal data pertama summary inbound yaitu ".$firstSummaryInbound->inbound_date, []);
        }
        
        // Validasi date_to tidak boleh lebih dari tanggal data terakhir
        if($dateTo && $lastSummaryInbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryInbound->inbound_date))){
            return new ResponseResource(false, "date_to tidak boleh lebih dari tanggal data terakhir summary inbound yaitu ".$lastSummaryInbound->inbound_date, []);
        }
        
        // Validasi date_from harus <= date_to
        if($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))){
            return new ResponseResource(false, "date_from tidak boleh lebih besar dari date_to", []);
        }
        
        $summaryInbound = SummaryInbound::query();

        // Filter logic berdasarkan date_from dan date_to
        if ($dateFrom && $dateTo) {
            // Jika keduanya ada: filter range
            $summaryInbound->whereBetween('inbound_date', [$dateFrom, $dateTo]);
        } elseif ($dateFrom && !$dateTo) {
            // Jika hanya date_from: filter untuk tanggal itu saja
            $summaryInbound->where('inbound_date', $dateFrom);
        } elseif (!$dateFrom && $dateTo) {
            // Jika hanya date_to: filter dari awal sampai date_to
            $summaryInbound->where('inbound_date', '<=', $dateTo);
        } else {
            // Default ke hari ini jika tidak ada filter
            $summaryInbound->where('inbound_date', $currentDate->toDateString());
        }

        $data = $summaryInbound->get();
        
        // Prepare response dengan date information
        $responseData = [
            'date' => [
                'current_date' => [
                    'date' => $currentDate->toDateString(),
                    'month' => $currentDate->format('F'), // November
                    'year' => $currentDate->format('Y')
                ],
                'date_from' => $dateFrom ? [
                    'date' => $dateFrom,
                    'month' => Carbon::parse($dateFrom)->format('F'),
                    'year' => Carbon::parse($dateFrom)->format('Y')
                ] : [
                    'date' => null,
                    'month' => null,
                    'year' => null
                ],
                'date_to' => $dateTo ? [
                    'date' => $dateTo,
                    'month' => Carbon::parse($dateTo)->format('F'),
                    'year' => Carbon::parse($dateTo)->format('Y')
                ] : [
                    'date' => null,
                    'month' => null,
                    'year' => null
                ]
            ],
            'data' => $data
        ];
        
        return new ResponseResource(true, "List of summary inbound", $responseData);
    }
  
}
