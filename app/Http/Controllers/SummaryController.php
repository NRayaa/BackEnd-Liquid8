<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Sale;
use App\Models\BulkySale;
use App\Models\New_product;
use App\Models\PaletProduct;
use Illuminate\Http\Request;
use App\Models\RepairProduct;
use App\Models\Product_Bundle;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Models\SummaryInbound;
use App\Models\SummaryOutbound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SummaryInboundExport;
use App\Http\Resources\ResponseResource;
use App\Exports\ProductSummaryInboundExport;
use App\Exports\CombinedSummaryInboundExport;
use App\Exports\CombinedSummaryOutboundExport;

class SummaryController extends Controller
{
    public function summaryInbound(Request $request)
    {
        set_time_limit(1200);
        ini_set('memory_limit', '1024M');
        try {
            DB::beginTransaction();
            $date = Carbon::now('Asia/Jakarta')->toDateString();
            $timestamp = Carbon::now('Asia/Jakarta')->toDateTimeString();

            // Log start process
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryinbound.log'),
            ])->info("=== SUMMARY INBOUND PROCESS STARTED ===", [
                'date' => $date,
                'timestamp' => $timestamp,
                'request_data' => $request->all()
            ]);

            // product display
            $getDataNp = New_product::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->whereNot('new_status_product', 'scrap_qcd')
                ->whereNull('new_quality->damaged')
                ->where('created_at', 'like', $date . '%')->first();

            $getDataSp = StagingProduct::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->whereNot('new_status_product', 'scrap_qcd')
                ->whereNull('new_quality->damaged')
                ->where('created_at', 'like', $date . '%')->first();

            $getDataPa = ProductApprove::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('created_at', 'like', $date . '%')->first();

            // data product outbound

            $getDataPb = Product_Bundle::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('actual_created_at', 'like', $date . '%')->first();

            // $getDataPalet = PaletProduct::selectRaw('
            //     COUNT(id) as qty,
            //     COALESCE(SUM(new_price_product), 0) as new_price_product,
            //     COALESCE(SUM(old_price_product), 0) as old_price_product,
            //     COALESCE(SUM(display_price), 0) as display_price
            // ')->where('actual_created_at', 'like', $date . '%')->first();

            $getDataRp = RepairProduct::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('actual_created_at', 'like', $date . '%')->first();

            // $getDataBs = BulkySale::selectRaw('
            //     COUNT(id) as qty,
            //     COALESCE(SUM(after_price_bulky_sale), 0) as new_price_product,
            //     COALESCE(SUM(old_price_bulky_sale), 0) as old_price_product,
            //     COALESCE(SUM(display_price), 0) as display_price
            // ')->where('actual_created_at', 'like', $date . '%')->first();

            // $getDataSale = Sale::selectRaw('
            //     COUNT(id) as qty,
            //     COALESCE(SUM(product_price_sale), 0) as new_price_product,
            //     COALESCE(SUM(product_old_price_sale), 0) as old_price_product,
            //     COALESCE(SUM(display_price), 0) as display_price
            // ')->where('actual_created_at', 'like', $date . '%')->first();

            // Log individual model data
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryinbound.log'),
            ])->info("Individual Model Data Retrieved", [
                'New_product' => $getDataNp,
                'StagingProduct' => $getDataSp,
                'ProductApprove' => $getDataPa,
                'Product_Bundle' => $getDataPb,
                // 'PaletProduct' => $getDataPalet,
                'RepairProduct' => $getDataRp,
                // 'BulkySale' => $getDataBs,
                // 'Sale' => $getDataSale
            ]);

            // Calculate totals
            $totalQty = ($getDataNp->qty ?? 0) + ($getDataSp->qty ?? 0) + ($getDataPb->qty ?? 0) +
                ($getDataPa->qty ?? 0) + ($getDataRp->qty ?? 0);

            $totalNewPrice = ($getDataNp->new_price_product ?? 0) + ($getDataSp->new_price_product ?? 0) +
                ($getDataPb->new_price_product ?? 0) + ($getDataPa->new_price_product ?? 0) +
                ($getDataRp->new_price_product ?? 0);
            $totalOldPrice = ($getDataNp->old_price_product ?? 0) + ($getDataSp->old_price_product ?? 0) +
                ($getDataPb->old_price_product ?? 0) + ($getDataPa->old_price_product ?? 0) +
                ($getDataRp->old_price_product ?? 0);
            $totalDisplayPrice = ($getDataNp->display_price ?? 0) + ($getDataSp->display_price ?? 0) +
                ($getDataPb->display_price ?? 0) + ($getDataPa->display_price ?? 0) +
                // ($getDataPalet->display_price ?? 0) + 
                ($getDataRp->display_price ?? 0);
            // Log calculated totals
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryinbound.log'),
            ])->info("Calculated Totals", [
                'total_qty' => $totalQty,
                'total_new_price' => $totalNewPrice,
                'total_old_price' => $totalOldPrice,
                'total_display_price' => $totalDisplayPrice
            ]);

            $result = SummaryInbound::updateOrCreate(
                ['inbound_date' => $date],
                [
                    'qty' => $totalQty,
                    'new_price_product' => $totalNewPrice,
                    'old_price_product' => $totalOldPrice,
                    'display_price' => $totalDisplayPrice,
                ]
            );

            DB::commit();

            // Log success
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryinbound.log'),
            ])->info("=== SUMMARY INBOUND PROCESS COMPLETED SUCCESSFULLY ===", [
                'result' => $result,
                'execution_time' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Summary inbound berhasil diproses untuk tanggal ' . $date,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            // Log error
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryinbound.log'),
            ])->error("=== SUMMARY INBOUND PROCESS FAILED ===", [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses summary inbound: ' . $e->getMessage(),
                'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ], 500);
        }
    }

    public function summaryOutbound(Request $request)
    {
        try {
            DB::beginTransaction();
            $date = Carbon::now('Asia/Jakarta')->toDateString();
            $timestamp = Carbon::now('Asia/Jakarta')->toDateTimeString();

            // Log start process
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryoutbound.log'),
            ])->info("=== SUMMARY OUTBOUND PROCESS STARTED ===", [
                'date' => $date,
                'timestamp' => $timestamp,
                'request_data' => $request->all()
            ]);

            // data product outbound
            $getDataNp = New_product::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where(function ($query) {
                $query->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })
                ->where('created_at', 'like', $date . '%')->first();

            $getDataSp = StagingProduct::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where(function ($query) {
                $query->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })->where('created_at', 'like', $date . '%')->first();

            $getDataPalet = PaletProduct::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(new_price_product), 0) as new_price_product,
                COALESCE(SUM(old_price_product), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('created_at', 'like', $date . '%')->first();

            // $getDataRp = RepairProduct::selectRaw('
            //     COUNT(id) as qty,
            //     COALESCE(SUM(new_price_product), 0) as new_price_product,
            //     COALESCE(SUM(old_price_product), 0) as old_price_product,
            //     COALESCE(SUM(display_price), 0) as display_price
            // ')->where('created_at', 'like', $date . '%')->first();

            $getDataBs = BulkySale::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(after_price_bulky_sale), 0) as new_price_product,
                COALESCE(SUM(old_price_bulky_sale), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('created_at', 'like', $date . '%')->first();

            $getDataSale = Sale::selectRaw('
                COUNT(id) as qty,
                COALESCE(SUM(product_price_sale), 0) as new_price_product,
                COALESCE(SUM(product_old_price_sale), 0) as old_price_product,
                COALESCE(SUM(display_price), 0) as display_price
            ')->where('created_at', 'like', $date . '%')->first();

            // Log individual model data
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryoutbound.log'),
            ])->info("Individual Model Data Retrieved", [
                // 'Product_Bundle' => $getDataPb,
                'PaletProduct' => $getDataPalet,
                // 'RepairProduct' => $getDataRp,
                'BulkySale' => $getDataBs,
                'Sale' => $getDataSale
            ]);

            // Calculate totals
            $totalQty = ($getDataPalet->qty ?? 0) + ($getDataBs->qty ?? 0) + ($getDataSale->qty ?? 0)
                + ($getDataNp->qty ?? 0) + ($getDataSp->qty ?? 0);

            $totalNewPrice = ($getDataPalet->new_price_product ?? 0) +
                ($getDataBs->new_price_product ?? 0) +
                ($getDataSale->new_price_product ?? 0) +
                ($getDataNp->new_price_product ?? 0) +
                ($getDataSp->new_price_product ?? 0);

            $totalOldPrice = ($getDataPalet->old_price_product ?? 0) +
                ($getDataBs->old_price_product ?? 0) +
                ($getDataSale->old_price_product ?? 0) +
                ($getDataNp->old_price_product ?? 0) +
                ($getDataSp->old_price_product ?? 0);

            $totalDisplayPrice = ($getDataPalet->display_price ?? 0) +
                ($getDataBs->display_price ?? 0) +
                ($getDataSale->display_price ?? 0) +
                ($getDataNp->display_price ?? 0) +
                ($getDataSp->display_price ?? 0);

            // Calculate discount (selisih display_price dengan price_sale)
            // For BulkySale: display_price - after_price_bulky_sale
            $discountBs = ($getDataBs->old_price_product ?? 0) - ($getDataBs->new_price_product ?? 0);
            // For Sale: display_price - product_price_sale
            $discountSale = ($getDataSale->display_price ?? 0) - ($getDataSale->new_price_product ?? 0);
            $totalDiscount = $discountBs + $discountSale;

            // Log calculated totals and discounts
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryoutbound.log'),
            ])->info("Calculated Totals and Discounts", [
                'total_qty' => $totalQty,
                'total_new_price' => $totalNewPrice,
                'total_old_price' => $totalOldPrice,
                'total_display_price' => $totalDisplayPrice,
                'discount_bulky_sale' => $discountBs,
                'discount_sale' => $discountSale,
                'total_discount' => $totalDiscount
            ]);

            $result = SummaryOutbound::updateOrCreate(
                ['outbound_date' => $date],
                [
                    'qty' => $totalQty,
                    'old_price_product' => $totalOldPrice,
                    'display_price_product' => $totalDisplayPrice,
                    'price_sale' => $totalNewPrice,
                    'discount' => $totalDiscount,
                ]
            );

            DB::commit();
            // Log success
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryoutbound.log'),
            ])->info("=== SUMMARY OUTBOUND PROCESS COMPLETED SUCCESSFULLY ===", [
                'result' => $result,
                'execution_time' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Summary outbound berhasil diproses untuk tanggal ' . $date,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            // Log error
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/summaryoutbound.log'),
            ])->error("=== SUMMARY OUTBOUND PROCESS FAILED ===", [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses summary outbound: ' . $e->getMessage(),
                'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
            ], 500);
        }
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
            if ($dateFrom && $firstSummaryInbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryInbound->inbound_date))) {
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_from tidak boleh kurang dari tanggal data pertama summary inbound yaitu " . $firstSummaryInbound->inbound_date,
                        'resource' => []
                    ]
                ], 422);
            }

            // Validasi date_to tidak boleh lebih dari tanggal data terakhir
            // if ($dateTo && $lastSummaryInbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryInbound->inbound_date))) {
            //     return response()->json([
            //         'data' => [
            //             'status' => false,
            //             'message' => "date_to tidak boleh lebih dari tanggal data terakhir summary inbound yaitu " . $lastSummaryInbound->inbound_date,
            //             'resource' => []
            //         ]
            //     ], 422);
            // }

            // Validasi date_from harus <= date_to
            if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
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

            // Simpan ke folder temporary di public (bukan storage)
            // Folder public/temp-exports harus sudah ada dan punya permission 775 dengan owner wmslq3138
            $publicPath = 'temp-exports';
            $publicDir = public_path($publicPath);

            // Pastikan folder ada
            if (!file_exists($publicDir)) {
                mkdir($publicDir, 0775, true);
            }

            $filePath = $publicPath . '/' . $fileName;

            // Simpan langsung ke public folder (bypass storage link issue)
            Excel::store(
                new CombinedSummaryInboundExport($dateFrom, $dateTo),
                $filePath,
                'public_direct' // Custom disk yang langsung ke public folder
            );

            // URL yang bisa diakses frontend
            $downloadUrl = url($publicPath . '/' . $fileName);

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

    public function exportCombinedSummaryOutbound(Request $request)
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

            // Validasi berdasarkan data summary outbound yang ada
            $firstSummaryOutbound = SummaryOutbound::orderBy('outbound_date', 'asc')->first();
            $lastSummaryOutbound = SummaryOutbound::orderBy('outbound_date', 'desc')->first();

            // Validasi date_from tidak boleh kurang dari tanggal data pertama
            if ($dateFrom && $firstSummaryOutbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryOutbound->outbound_date))) {
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_from tidak boleh kurang dari tanggal data pertama summary outbound yaitu " . $firstSummaryOutbound->outbound_date,
                        'resource' => []
                    ]
                ], 422);
            }

            // Validasi date_to tidak boleh lebih dari tanggal data terakhir
            if ($dateTo && $lastSummaryOutbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryOutbound->outbound_date))) {
                return response()->json([
                    'data' => [
                        'status' => false,
                        'message' => "date_to tidak boleh lebih dari tanggal data terakhir summary outbound yaitu " . $lastSummaryOutbound->outbound_date,
                        'resource' => []
                    ]
                ], 422);
            }

            // Validasi date_from harus <= date_to
            if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
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
            $fileName = 'combined_summary_outbound_' . $fileNamePart . '.xlsx';

            // Simpan ke folder temporary di public (bukan storage)
            $publicPath = 'temp-exports';
            $publicDir = public_path($publicPath);

            // Pastikan folder ada
            if (!file_exists($publicDir)) {
                mkdir($publicDir, 0775, true);
            }

            $filePath = $publicPath . '/' . $fileName;

            // Simpan langsung ke public folder (bypass storage link issue)
            Excel::store(
                new CombinedSummaryOutboundExport($dateFrom, $dateTo),
                $filePath,
                'public_direct'
            );

            // URL yang bisa diakses frontend
            $downloadUrl = url($publicPath . '/' . $fileName);

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
            return response()->json([
                'status' => false,
                'message' => "Format date_from harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }
        if ($dateTo && !Carbon::hasFormat($dateTo, 'Y-m-d')) {
            return response()->json([
                'status' => false,
                'message' => "Format date_to harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }

        // Validasi berdasarkan data summary inbound yang ada
        $firstSummaryInbound = SummaryInbound::orderBy('inbound_date', 'asc')->first();
        $lastSummaryInbound = SummaryInbound::orderBy('inbound_date', 'desc')->first();

        // Validasi date_from tidak boleh kurang dari tanggal data pertama
        if ($dateFrom && $firstSummaryInbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryInbound->inbound_date))) {
            return response()->json([
                'status' => false,
                'message' => "date_from tidak boleh kurang dari tanggal data pertama summary inbound yaitu " . $firstSummaryInbound->inbound_date,
                'data' => []
            ], 422);
        }

        // Validasi date_to tidak boleh lebih dari tanggal data terakhir
        if ($dateTo && $lastSummaryInbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryInbound->inbound_date))) {
            return response()->json([
                'status' => false,
                'message' => "date_to tidak boleh lebih dari tanggal data terakhir summary inbound yaitu " . $lastSummaryInbound->inbound_date,
                'data' => []
            ], 422);
        }

        // Validasi date_from harus <= date_to
        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            return response()->json([
                'status' => false,
                'message' => "date_from tidak boleh lebih besar dari date_to",
                'data' => []
            ], 422);
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

    public function listSummaryBoth(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $currentDate = Carbon::now('Asia/Jakarta');

        // Log untuk debugging
        Log::info("listSummaryBoth called", [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'current_date' => $currentDate->toDateString()
        ]);

        // Validasi format tanggal jika ada
        if ($dateFrom && !Carbon::hasFormat($dateFrom, 'Y-m-d')) {
            return response()->json([
                'status' => false,
                'message' => "Format date_from harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }
        if ($dateTo && !Carbon::hasFormat($dateTo, 'Y-m-d')) {
            return response()->json([
                'status' => false,
                'message' => "Format date_to harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }

        // Validasi date_from harus <= date_to
        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            return response()->json([
                'status' => false,
                'message' => "date_from tidak boleh lebih besar dari date_to",
                'data' => []
            ], 422);
        }

        $reportDate = $currentDate->toDateString();

        $reportDate = $currentDate->toDateString();
        if ($dateTo) {
            $reportDate = $dateTo;
        } elseif ($dateFrom) {
            $reportDate = $dateFrom;
        }

        $dailyInbound = SummaryInbound::where('inbound_date', $reportDate)->first();
        $dailyOutbound = SummaryOutbound::where('outbound_date', $reportDate)->first();

        if ($dailyInbound && $dailyOutbound) {
            $summaryReport = [
                'begin_balance' => (float) $dailyInbound->old_price_product,
                'end_balance'   => (float) $dailyOutbound->display_price_product,
                'qty_in'        => (int) $dailyInbound->qty,
                'qty_out'       => (int) $dailyOutbound->qty,
                'price_in'      => (float) $dailyInbound->new_price_product,
                'price_out'     => (float) $dailyOutbound->display_price_product,
            ];
        } else {
            // inbound
            $npIn = New_product::whereNotIn('new_status_product', ['scrap_qcd'])
                ->whereNull('new_quality->damaged')
                ->where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
                ->first();

            $spIn = StagingProduct::whereNotIn('new_status_product', ['scrap_qcd'])
                ->whereNull('new_quality->damaged')
                ->where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
                ->first();

            // 2. Product Approve
            $paIn = ProductApprove::where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
                ->first();

            // 3. Product Bundle
            $pbIn = Product_Bundle::where('actual_created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
                ->first();

            // 4. Repair Product
            $rpIn = RepairProduct::where('actual_created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
                ->first();

            $realtimeQtyIn = ($npIn->qty ?? 0) + ($spIn->qty ?? 0) + ($paIn->qty ?? 0) + ($pbIn->qty ?? 0) + ($rpIn->qty ?? 0);
            $realtimePriceIn = ($npIn->new_price ?? 0) + ($spIn->new_price ?? 0) + ($paIn->new_price ?? 0) + ($pbIn->new_price ?? 0) + ($rpIn->new_price ?? 0);
            $realtimeOldPriceIn = ($npIn->old_price ?? 0) + ($spIn->old_price ?? 0) + ($paIn->old_price ?? 0) + ($pbIn->old_price ?? 0) + ($rpIn->old_price ?? 0);

            // Outbound
            $npOut = New_product::where(function ($q) {
                $q->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })
                ->where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(display_price) as display_price')
                ->first();

            $spOut = StagingProduct::where(function ($q) {
                $q->whereIn('new_status_product', ['scrap_qcd'])
                    ->orWhereNotNull('new_quality->damaged');
            })
                ->where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(display_price) as display_price')
                ->first();

            $palOut = PaletProduct::where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(new_price_product) as new_price, SUM(display_price) as display_price')
                ->first();

            $bsOut = BulkySale::where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(after_price_bulky_sale) as deal_price, SUM(display_price) as display_price')
                ->first();

            $saleOut = Sale::where('created_at', 'like', $reportDate . '%')
                ->selectRaw('COUNT(id) as qty, SUM(product_price_sale) as deal_price, SUM(display_price) as display_price')
                ->first();

            $realtimeQtyOut = ($npOut->qty ?? 0) + ($spOut->qty ?? 0) + ($palOut->qty ?? 0) + ($bsOut->qty ?? 0) + ($saleOut->qty ?? 0);

            // $realtimePriceOut = ($npOut->new_price ?? 0) + ($spOut->new_price ?? 0) + ($palOut->new_price ?? 0) +
            //     ($bsOut->deal_price ?? 0) + ($saleOut->deal_price ?? 0);

            $realtimeDisplayOut = ($npOut->display_price ?? 0) + ($spOut->display_price ?? 0) + ($palOut->display_price ?? 0) +
                ($bsOut->display_price ?? 0) + ($saleOut->display_price ?? 0);


            $summaryReport = [
                'begin_balance' => (float) $realtimeOldPriceIn,      // Total Old Price Inbound
                'end_balance'   => (float) $realtimeDisplayOut,      // Total Display Price Outbound
                'qty_in'        => (int) $realtimeQtyIn,
                'qty_out'       => (int) $realtimeQtyOut,
                'price_in'      => (float) $realtimePriceIn,
                'price_out'     => (float) $realtimeDisplayOut,
            ];
        }
        // Query untuk summary inbound
        $summaryInbound = SummaryInbound::query();

        // Query untuk summary outbound
        $summaryOutbound = SummaryOutbound::query();

        // Filter logic berdasarkan date_from dan date_to untuk kedua query
        if ($dateFrom && $dateTo) {
            // Jika keduanya ada: filter range
            $summaryInbound->whereBetween('inbound_date', [$dateFrom, $dateTo]);
            $summaryOutbound->whereBetween('outbound_date', [$dateFrom, $dateTo]);
        } elseif ($dateFrom && !$dateTo) {
            // Jika hanya date_from: filter untuk tanggal itu saja
            $summaryInbound->where('inbound_date', $dateFrom);
            $summaryOutbound->where('outbound_date', $dateFrom);
        } elseif (!$dateFrom && $dateTo) {
            // Jika hanya date_to: filter dari awal sampai date_to
            $summaryInbound->where('inbound_date', '<=', $dateTo);
            $summaryOutbound->where('outbound_date', '<=', $dateTo);
        } else {
            // Default ke hari ini jika tidak ada filter
            $summaryInbound->where('inbound_date', $currentDate->toDateString());
            $summaryOutbound->where('outbound_date', $currentDate->toDateString());
        }

        // Get data (akan return array kosong jika tidak ada data)
        $dataInbound = $summaryInbound->get();
        $dataOutbound = $summaryOutbound->get();

        // Get data 1 hari sebelumnya (skip hari Minggu karena toko tutup)
        $timeNow = Carbon::now('Asia/Jakarta');
        $dateBeforeInbound = null;
        $dateBeforeOutbound = null;

        // Cari data inbound 1 hari sebelumnya (maksimal cek 7 hari ke belakang, skip Minggu)
        for ($i = 1; $i <= 7; $i++) {
            $checkDate = $timeNow->copy()->subDays($i);

            // Skip jika hari Minggu (0 = Sunday)
            if ($checkDate->dayOfWeek === 0) {
                continue;
            }

            $foundInbound = SummaryInbound::where('inbound_date', $checkDate->toDateString())->first();
            if ($foundInbound) {
                $dateBeforeInbound = $foundInbound;
                break;
            }
        }

        // Cari data outbound 1 hari sebelumnya (maksimal cek 7 hari ke belakang, skip Minggu)
        for ($i = 1; $i <= 7; $i++) {
            $checkDate = $timeNow->copy()->subDays($i);

            // Skip jika hari Minggu (0 = Sunday)
            if ($checkDate->dayOfWeek === 0) {
                continue;
            }

            $foundOutbound = SummaryOutbound::where('outbound_date', $checkDate->toDateString())->first();
            if ($foundOutbound) {
                $dateBeforeOutbound = $foundOutbound;
                break;
            }
        }

        // Prepare response dengan date information dan kedua data
        $responseData = [
            'date' => [
                'current_date' => [
                    'date' => $currentDate->toDateString(),
                    'month' => $currentDate->format('F'),
                    'year' => $currentDate->format('Y')
                ],
                'date_from' => $dateFrom ? [
                    'date' => $dateFrom,
                    'month' => Carbon::parse($dateFrom)->format('F'),
                    'year' => Carbon::parse($dateFrom)->format('Y')
                ] : null,
                'date_to' => $dateTo ? [
                    'date' => $dateTo,
                    'month' => Carbon::parse($dateTo)->format('F'),
                    'year' => Carbon::parse($dateTo)->format('Y')
                ] : null
            ],
            'summary_report' => $summaryReport,
            'inbound' => $dataInbound,
            'outbound' => $dataOutbound,
            'data_before' => [
                'inbound' => $dateBeforeInbound,
                'outbound' => $dateBeforeOutbound
            ]
        ];

        return new ResponseResource(true, "List of summary inbound and outbound", $responseData);
    }

    public function listSummaryOutbound(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $currentDate = Carbon::now('Asia/Jakarta');

        // Validasi format tanggal jika ada
        if ($dateFrom && !Carbon::hasFormat($dateFrom, 'Y-m-d')) {
            return response()->json([
                'status' => false,
                'message' => "Format date_from harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }
        if ($dateTo && !Carbon::hasFormat($dateTo, 'Y-m-d')) {
            return response()->json([
                'status' => false,
                'message' => "Format date_to harus Y-m-d (contoh: 2025-11-17)",
                'data' => []
            ], 422);
        }

        // Validasi berdasarkan data summary outbound yang ada
        $firstSummaryOutbound = SummaryOutbound::orderBy('outbound_date', 'asc')->first();
        $lastSummaryOutbound = SummaryOutbound::orderBy('outbound_date', 'desc')->first();

        // Validasi date_from tidak boleh kurang dari tanggal data pertama
        if ($dateFrom && $firstSummaryOutbound && Carbon::parse($dateFrom)->lt(Carbon::parse($firstSummaryOutbound->outbound_date))) {
            return response()->json([
                'status' => false,
                'message' => "date_from tidak boleh kurang dari tanggal data pertama summary outbound yaitu " . $firstSummaryOutbound->outbound_date,
                'data' => []
            ], 422);
        }

        // Validasi date_to tidak boleh lebih dari tanggal data terakhir
        if ($dateTo && $lastSummaryOutbound && Carbon::parse($dateTo)->gt(Carbon::parse($lastSummaryOutbound->outbound_date))) {
            return response()->json([
                'status' => false,
                'message' => "date_to tidak boleh lebih dari tanggal data terakhir summary outbound yaitu " . $lastSummaryOutbound->outbound_date,
                'data' => []
            ], 422);
        }

        // Validasi date_from harus <= date_to
        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            return response()->json([
                'status' => false,
                'message' => "date_from tidak boleh lebih besar dari date_to",
                'data' => []
            ], 422);
        }

        $summaryOutbound = SummaryOutbound::query();

        // Filter logic berdasarkan date_from dan date_to
        if ($dateFrom && $dateTo) {
            // Jika keduanya ada: filter range
            $summaryOutbound->whereBetween('outbound_date', [$dateFrom, $dateTo]);
        } elseif ($dateFrom && !$dateTo) {
            // Jika hanya date_from: filter untuk tanggal itu saja
            $summaryOutbound->where('outbound_date', $dateFrom);
        } elseif (!$dateFrom && $dateTo) {
            // Jika hanya date_to: filter dari awal sampai date_to
            $summaryOutbound->where('outbound_date', '<=', $dateTo);
        } else {
            // Default ke hari ini jika tidak ada filter
            $summaryOutbound->where('outbound_date', $currentDate->toDateString());
        }

        $data = $summaryOutbound->get();

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
