<?php

namespace App\Http\Controllers;

use App\Exports\ListAnalyticSalesExport;
use App\Exports\ProductExpiredExport;
use App\Exports\StorageReportExport;
use App\Http\Resources\ResponseResource;
use App\Models\Bundle;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Sale;
use App\Models\SaleDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $thisYear = date('Y');

        $countInboundOutbound = New_Product::select(
            DB::raw('MONTH(created_at) as month'),
            DB::raw('SUM(CASE WHEN new_status_product IN ("sale", "migrate") THEN 1 ELSE 0 END) AS outbound_count'),
            DB::raw('COUNT(*) AS inbound_count')
        )
            ->whereYear('created_at', $thisYear)
            ->groupBy(DB::raw('MONTH(created_at)'))
            ->get();

        //Sale Category
        $totalNewProductSaleByCategory = New_product::select('new_category_product', DB::raw('COUNT(*) as total'))
            ->where('new_status_product', 'sale')
            ->groupBy('new_category_product')
            ->get();
        $totalNewProductSaleByCategory[] = ['all_total' => $totalNewProductSaleByCategory->sum('total')];

        //Inbound Data
        $document = Document::select('base_document', 'created_at', 'total_column_in_document')->latest()->paginate(8);

        //Expired Product
        $totalNewProductExpiredByCategory = New_product::select('new_category_product', DB::raw('COUNT(*) as total'))
            ->where('new_status_product', 'expired')
            ->groupBy('new_category_product')
            ->get();
        $totalNewProductExpiredByCategory[] = ['all_total' => $totalNewProductExpiredByCategory->sum('total')];

        //Product by Category
        $totalNewProductByCategory = New_product::select('new_category_product', DB::raw('COUNT(*) as total'))
            ->whereNotNull('new_category_product')
            ->whereIn('new_status_product', ['display', 'promo', 'bundle'])
            ->groupBy('new_category_product')
            ->get();
        $totalNewProductByCategory[] = ['all_total' => $totalNewProductByCategory->sum('total')];

        $resource = new ResponseResource(
            true,
            "Data dashboard analytic",
            [
                "chart_inbound_outbound" => $countInboundOutbound,
                "product_sales" => $totalNewProductSaleByCategory,
                "inbound_data" => $document,
                "expired_data" => $totalNewProductExpiredByCategory,
                "product_data" => $totalNewProductByCategory,
            ]
        );
        return $resource->response();
    }

    public function index2(Request $request)
    {
        $year = $request->year ?? date('Y');
        $month = $request->month ?? date('m');

        $summeryMontlyCustomerTransaction = SaleDocument::with('buyer:id,name_buyer')
            ->selectRaw('buyer_id_document_sale, SUM(total_price_document_sale) as total_sales, DATE_FORMAT(created_at, "%M") as month')
            ->whereYear('created_at', $year)
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%M")'), 'buyer_id_document_sale')
            ->get();

        $dailyTransactionSummary = SaleDocument::with('sales.newProduct')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('d');
            })
            ->map(function ($saleDocuments, $day) {
                $saleValue = $saleDocuments->sum('total_price_document_sale');

                $totalOldPrice = $saleDocuments->sum(function ($saleDocument) {
                    return $saleDocument->sales->sum(function ($sale) {
                        return $sale->newProduct->old_price_product ?? 0;
                    });
                });
                $initialCapital = $totalOldPrice * 0.2;

                return [
                    'display_price' => $totalOldPrice,
                    'initial_capital' => $initialCapital,
                    'sale_value' => $saleValue,
                    'day' => $day,
                ];
            });

        $typeBuyer = Buyer::selectRaw('type_buyer, COUNT(*) as total_buyer')
            ->groupBy('type_buyer')
            ->get()
            ->pluck('total_buyer', 'type_buyer')
            ->toArray();

        $monthlyTransactionSummary = SaleDocument::with('sales.newProduct')
            ->where('status_document_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('F');
            })
            ->map(function ($saleDocuments, $month) {
                $saleValue = $saleDocuments->sum('total_price_document_sale');

                $totalOldPrice = $saleDocuments->sum(function ($saleDocument) {
                    return $saleDocument->sales->sum(function ($sale) {
                        return $sale->newProduct->old_price_product ?? 0;
                    });
                });
                $initialCapital = $totalOldPrice * 0.2;

                return [
                    'display_price' => $totalOldPrice,
                    'initial_capital' => $initialCapital,
                    'sale_value' => $saleValue,
                    'month' => $month,
                ];
            });

        $resource = new ResponseResource(
            true,
            "Data dashboard analytic",
            // [
            //     "monthly_transaction_customer" => ,
            //     "monthly_transaction_summary" => ,
            //     "daily_transaction_summary" => ,
            //     "type_buyer" => ,
            // ]
            $dailyTransactionSummary
        );
        return $resource->response();
    }

    public function summaryTransaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'y' => 'nullable|date_format:Y|digits:4', // Format tahun (misalnya, 2024)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid input format. Year should be in format YYYY.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $year = $request->input('y', Carbon::now()->format('Y'));

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentYear = $currentDate->format('Y');

        //bulan yang di pilih
        $selectedDate = Carbon::createFromFormat('Y', $year);
        $selectedYear = $selectedDate->format('Y');

        //bulan seblumnya
        $prevMonthDate = $selectedDate->copy()->subYear();
        $prevYear = $prevMonthDate->format('Y');

        //bulan yang akan datang
        $nextMonthDate = $selectedDate->copy()->addYear();
        $nextYear = $nextMonthDate->format('Y');

        $summaryTransactionTotal = SaleDocument::selectRaw('
                COUNT(*) as total_transaction,
                COUNT(DISTINCT buyer_id_document_sale) as total_customer,
                SUM(total_price_document_sale) as value_transaction
            ')
            ->where('status_document_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->first();

        // Casting nilai agar menjadi integer atau float
        $summaryTransactionTotal->total_transaction = (float) $summaryTransactionTotal->total_transaction;
        $summaryTransactionTotal->total_customer = (float) $summaryTransactionTotal->total_customer;
        $summaryTransactionTotal->value_transaction = (float) $summaryTransactionTotal->value_transaction;

        // Buat array kosong untuk menyimpan summary sales dari Januari sampai Desember
        $summaryTransaction = [];

        // Loop untuk menghasilkan summary sales untuk setiap bulan
        for ($month = 1; $month <= 12; $month++) {
            $saleDocument = SaleDocument::selectRaw('
                COUNT(*) as total_transaction,
                COUNT(DISTINCT buyer_id_document_sale) as total_customer,
                SUM(total_price_document_sale) as value_transaction
                ')
                ->where('status_document_sale', 'selesai')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->first(); // Menggunakan first() untuk mengambil satu hasil

            // Jika tidak ada data untuk bulan ini, isi dengan nilai default
            if (!$saleDocument) {
                $saleDocument = (object) [
                    'total_transaction' => 0,
                    'total_customer' => 0,
                    'value_transaction' => 0,
                ];
            } else {
                // Casting nilai agar menjadi integer atau float
                $saleDocument->total_transaction = (float) $saleDocument->total_transaction;
                $saleDocument->total_customer = (float) $saleDocument->total_customer;
                $saleDocument->value_transaction = (float) $saleDocument->value_transaction;
            }

            // Tambahkan hasil ke dalam array summarySales
            $summaryTransaction[] = [
                'month' => Carbon::createFromDate($year, $month, 1)->format('F'), // Format nama bulan
                'total_transaction' => $saleDocument->total_transaction,
                'total_customer' => $saleDocument->total_customer,
                'value_transaction' => $saleDocument->value_transaction,
            ];
        }

        $resource = new ResponseResource(
            true,
            "Data Summary Transaksi",
            [
                'year' => [
                    'current_year' => [
                        'year' => $currentYear,
                    ],
                    'prev_year' => [
                        'year' => $prevYear,
                    ],
                    'selected_year' => [
                        'year' => $selectedYear,
                    ],
                    'next_year' => [
                        'year' => $nextYear,
                    ],
                ],
                'final_total' => $summaryTransactionTotal,
                'charts' => $summaryTransaction,
            ]
        );

        return $resource->response();
    }

    public function summarySales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'm' => 'nullable|date_format:m|digits:2', // Format bulan (01-12)
            'y' => 'nullable|date_format:Y|digits:4', // Format tahun (misalnya, 2024)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid input format. Month should be in format MM and year should be in format YYYY.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $month = $request->input('m', Carbon::now()->format('m'));
        $year = $request->input('y', Carbon::now()->format('Y'));

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentMonth = $currentDate->format('m');
        $currentYear = $currentDate->format('Y');

        //bulan yang di pilih
        $selectedDate = Carbon::createFromFormat('Y-m', $year . '-' . $month);
        $selectedMonth = $selectedDate->format('F');
        $selectedYear = $selectedDate->format('Y');

        //bulan seblumnya
        $prevMonthDate = $selectedDate->copy()->subMonth();
        $prevMonth = $prevMonthDate->format('m');
        $prevYear = $prevMonthDate->format('Y');

        //bulan yang akan datang
        $nextMonthDate = $selectedDate->copy()->addMonth();
        $nextMonth = $nextMonthDate->format('m');
        $nextYear = $nextMonthDate->format('Y');

        $anualSales = Sale::selectRaw('
                SUM(product_qty_sale) as qty_sale,
                SUM(display_price) as display_price_sale,
                SUM(product_price_sale) as after_discount_sale
            ')
            ->where('status_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->first();

        $summarySales = Sale::selectRaw('
                product_category_sale,
                SUM(product_qty_sale) as qty_sale,
                SUM(display_price) as display_price_sale,
                SUM(product_price_sale) as after_discount_sale
            ')
            ->where('status_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->groupBy('product_category_sale')
            ->get()
            ->map(function ($item) {
                return [
                    'product_category_sale' => $item->product_category_sale,
                    'qty_sale' => (int) $item->qty_sale,
                    'display_price_sale' => (float) $item->display_price_sale,
                    'after_discount_sale' => (float) $item->after_discount_sale,
                ];
            });

        $resource = new ResponseResource(
            true,
            "Data Summary Penjualan",
            [
                'month' => [
                    'current_month' => [
                        'month' => $currentMonth,
                        'year' => $currentYear,
                    ],
                    'prev_month' => [
                        'month' => $prevMonth,
                        'year' => $prevYear,
                    ],
                    'selected_month' => [
                        'month' => $selectedMonth,
                        'year' => $selectedYear,
                    ],
                    'next_month' => [
                        'month' => $nextMonth,
                        'year' => $nextYear,
                    ],
                ],
                'anual_sales' => $anualSales,
                'chart' => $summarySales,
            ]
        );

        return $resource->response();
    }

    public function storageReport()
    {
        //tanggal sekarang 
        $currentDate = Carbon::now();
        $currentMonth = $currentDate->format('F');
        $currentYear = $currentDate->format('Y');

        $categoryNewProduct = New_product::selectRaw('
                new_category_product as category_product,
                COUNT(new_category_product) as total_category,
                SUM(new_price_product) as total_price_category
            ')
            ->whereNotNull('new_category_product')
            ->where('new_tag_product', null)
            ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
            ->where(function ($query) {
                $query->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired');
            })
            ->groupBy('category_product');

        $categoryBundle = Bundle::selectRaw('
                category as category_product,
                COUNT(category) as total_category,
                SUM(total_price_custom_bundle) as total_price_category
            ')
            ->whereNotNull('category')
            ->where('name_color', null)
            ->whereNotIn('product_status', ['bundle'])
            ->groupBy('category_product');

        // merge / gabung kedua hasil query diatas
        $categoryCount = $categoryNewProduct->union($categoryBundle)->get();

        $tagProductCount = New_product::selectRaw(' 
                new_tag_product as tag_product,
                COUNT(new_tag_product) as total_tag_product,
                SUM(new_price_product) as total_price_tag_product
            ')
            ->whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
            ->where('new_status_product', 'display')
            ->groupBy('new_tag_product')
            ->get();

        $resource = new ResponseResource(
            true,
            "Laporan Data Perkategori",
            [
                'month' => [
                    'current_month' => [
                        'month' => $currentMonth,
                        'year' => $currentYear,
                    ],
                ],
                'chart' => [
                    'category' => $categoryCount,
                    'tag_product' => $tagProductCount,
                ],
                'total_all_category' => $categoryCount->sum('total_category'),
                'total_all_price_category' => $categoryCount->sum('total_price_category'),
                'total_all_tag_product' => $tagProductCount->sum('total_tag_product'),
                'total_all_price_tag_product' => $tagProductCount->sum('total_price_tag_product'),
            ]
        );

        return $resource->response();
    }

    public function exportStorageReport()
    {
        try {
            $dataExport = $this->storageReport();
            $dataExport = $dataExport->getData(true);

            $listStorageReport = $dataExport['data']['resource']['chart']['category'];

            if (empty($listStorageReport)) {
                return response()->json(['errors' => "data kosong! tidak bisa di export!"], 422);
            }

            $customDataExport = array_map(function ($data) {
                return [
                    'Category Name' => $data['category_product'],
                    'Total Product'   => $data['total_category'],
                    'Value Product' => $data['total_price_category'],
                ];
            }, $listStorageReport);

            $fileName = 'exports/storage-report.xlsx';

            Excel::store(new StorageReportExport($customDataExport), $fileName, 'public');

            $fileUrl = Storage::disk('public')->url($fileName);

            $resource = new ResponseResource('true', 'File export berhasil di buat!', $fileUrl);
        } catch (\Exception $e) {
            $resource = new ResponseResource('false', 'Gagal membuat file export!', $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
        return $resource;
    }

    public function generalSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $fromDate = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : Carbon::now()->startOfMonth()->startOfDay();
        $toDate = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : Carbon::now()->endOfMonth()->endOfDay();

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentMonth = $currentDate->format('F');
        $currentYear = $currentDate->format('Y');

        $generalSale = SaleDocument::selectRaw('
                SUM(total_price_document_sale) as total_price_sale,
                SUM(total_old_price_document_sale) as total_display_price,
                code_document_sale,
                buyer_name_document_sale,
                DATE(created_at) as tgl
            ')
            ->where('status_document_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('tgl', 'code_document_sale', 'buyer_name_document_sale')
            ->get()
            ->groupBy('tgl')
            ->map(function ($salesOnDate) {
                $total_price_sale = $salesOnDate->sum('total_price_sale');
                $total_display_price = $salesOnDate->sum('total_display_price');
                $date = Carbon::parse($salesOnDate->first()->tgl)->format('d-m-Y');
                return [
                    "date" => $date,
                    "total_price_sale" => $total_price_sale,
                    "total_display_price" => $total_display_price,
                ];
            })->values();

        $listDocumentSale = SaleDocument::selectRaw('
                id,
                total_price_document_sale as total_purchase,
                total_old_price_document_sale as total_display_price,
                code_document_sale,
                buyer_name_document_sale
            ')
            ->where('status_document_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $resource = new ResponseResource(
            true,
            "Laporan Data General",
            [
                'month' => [
                    'current_month' => [
                        'month' => $currentMonth,
                        'year' => $currentYear,
                    ],
                    'date_from' => [
                        'date' => $fromInput ? $fromDate->format('d') : null,
                        'month' => $fromInput ? $fromDate->format('M') : null,
                        'year' => $fromInput ? $fromDate->format('Y') : null,
                    ],
                    'date_to' => [
                        'date' => $toInput ? $toDate->format('d') : null,
                        'month' => $toInput ? $toDate->format('M') : null,
                        'year' => $toInput ? $toDate->format('Y') : null,
                    ],
                ],
                'chart' => $generalSale,
                'list_document_sale' => $listDocumentSale,
            ]
        );

        return $resource->response();
    }

    public function monthlyAnalyticSales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $fromDate = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : Carbon::now()->startOfMonth()->startOfDay();
        $toDate = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : Carbon::now()->endOfMonth()->endOfDay();

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentMonth = $currentDate->format('F');
        $currentYear = $currentDate->format('Y');

        $analyticSalesMonthly = Sale::selectRaw('
                    DATE(created_at) as tgl,
                    product_category_sale,
                    COUNT(product_category_sale) as total_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
            ->where('status_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('tgl', 'product_category_sale')
            ->orderBy('tgl')
            ->get()
            ->groupBy('tgl')
            ->map(function ($salesOnDate) {
                $categories = $salesOnDate->mapWithKeys(function ($item) {
                    return [$item->product_category_sale => $item->total_category];
                });
                $date = Carbon::parse($salesOnDate->first()->tgl)->format('d-m-Y');
                return array_merge([
                    'date' => $date,
                ], $categories->toArray());
            })->values();

        $listAnalyticSales = Sale::selectRaw('
                    product_category_sale,
                    COUNT(product_category_sale) as total_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
            ->where('status_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->groupBy('product_category_sale')
            ->get();

        $monthlySummary = Sale::selectRaw('
                    COUNT(product_category_sale) as total_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
            ->where('status_sale', 'selesai')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->first();

        $resource = new ResponseResource(
            true,
            "Laporan Data Sale",
            [
                'month' => [
                    'current_month' => [
                        'month' => $currentMonth,
                        'year' => $currentYear,
                    ],
                    'date_from' => [
                        'date' => $fromInput ? $fromDate->format('d') : null,
                        'month' => $fromInput ? $fromDate->format('M') : null,
                        'year' => $fromInput ? $fromDate->format('Y') : null,
                    ],
                    'date_to' => [
                        'date' => $toInput ? $toDate->format('d') : null,
                        'month' => $toInput ? $toDate->format('M') : null,
                        'year' => $toInput ? $toDate->format('Y') : null,
                    ],
                ],
                'chart' => $analyticSalesMonthly,
                'list_analytic_sale' => $listAnalyticSales,
                'monthly_summary' => $monthlySummary,
            ]
        );

        return $resource->response();
    }

    public function exportMonthlyAnalyticSales(Request $request)
    {
        try {
            $dataExport = $this->monthlyAnalyticSales($request);
            $dataExport = $dataExport->getData(true);

            if (!empty($dataExport['error'])) {
                return response()->json($dataExport, 422);
            }

            $listAnalyticSale = $dataExport['data']['resource']['list_analytic_sale'];

            $customDataExport = array_map(function ($data) {
                return [
                    'Category Name' => $data['product_category_sale'],
                    'Qty'           => $data['total_category'],
                    'Display Price' => $data['display_price_sale'],
                    'Sale Price'    => $data['purchase'],
                ];
            }, $listAnalyticSale);

            $fileName = 'exports/list-monthly-analytic-sales.xlsx';

            Excel::store(new ListAnalyticSalesExport($customDataExport), $fileName, 'public');

            $fileUrl = Storage::disk('public')->url($fileName);

            $resource = new ResponseResource('true', 'File export berhasil di buat!', $fileUrl);
        } catch (\Exception $e) {
            $resource = new ResponseResource('false', 'Gagal membuat file export!', $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
        return $resource;
    }

    public function yearlyAnalyticSales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'y' => 'nullable|date_format:Y|digits:4', // Format tahun (misalnya, 2024)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid input format. Year should be in format YYYY.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $year = $request->input('y', Carbon::now()->format('Y'));

        //tanggal sekarang
        $currentDate = Carbon::now();
        $currentYear = $currentDate->format('Y');

        //bulan yang di pilih
        $selectedDate = Carbon::createFromFormat('Y', $year);
        $selectedYear = $selectedDate->format('Y');

        //bulan seblumnya
        $prevMonthDate = $selectedDate->copy()->subYear();
        $prevYear = $prevMonthDate->format('Y');

        //bulan yang akan datang
        $nextMonthDate = $selectedDate->copy()->addYear();
        $nextYear = $nextMonthDate->format('Y');

        $analyticSalesYearly = [];

        // Loop untuk menghasilkan summary sales untuk setiap bulan
        for ($month = 1; $month <= 12; $month++) {
            $sale = Sale::selectRaw('
                    COUNT(product_category_sale) as total_all_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
                ->where('status_sale', 'selesai')
                ->whereYear('created_at', $year ?? $currentYear)
                ->whereMonth('created_at', $month)
                ->first();

            $saleCategory = Sale::selectRaw('
                    product_category_sale,
                    COUNT(product_category_sale) as total_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
                ->where('status_sale', 'selesai')
                ->whereYear('created_at', $year ?? $currentYear)
                ->whereMonth('created_at', $month)
                ->groupBy('product_category_sale')
                ->pluck('total_category', 'product_category_sale')
                ->toArray();

            $analyticSalesYearly[] = array_merge(
                [
                    'month' => Carbon::createFromDate($year ?? $currentYear, $month, 1)->format('F'),
                    'total_all_category' => $sale->total_all_category,
                    'display_price_sale' => $sale->display_price_sale,
                    'purchase' => $sale->purchase,
                ],
                $saleCategory
            );
        }

        $listAnalyticSales = Sale::selectRaw('
                    product_category_sale,
                    COUNT(product_category_sale) as total_category,
                    SUM(product_old_price_sale) as display_price_sale,
                    SUM(product_price_sale) as purchase
                ')
            ->where('status_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->groupBy('product_category_sale')
            ->get();

        $analyticalSalesSummary = Sale::selectRaw('
                    COUNT(product_category_sale) as total_all_category,
                    SUM(product_old_price_sale) as total_display_price_sale,
                    SUM(product_price_sale) as total_product_price_sale
                ')
            ->where('status_sale', 'selesai')
            ->whereYear('created_at', $year)
            ->first();

        $resource = new ResponseResource(
            true,
            "Laporan Data Sale",
            [
                'year' => [
                    'current_year' => [
                        'year' => $currentYear,
                    ],
                    'prev_year' => [
                        'year' => $prevYear,
                    ],
                    'selected_year' => [
                        'year' => $selectedYear,
                    ],
                    'next_year' => [
                        'year' => $nextYear,
                    ],
                ],
                'chart' => $analyticSalesYearly,
                'list_analytic_sale' => $listAnalyticSales,
                'annual_summary' => $analyticalSalesSummary,
            ]
        );

        return $resource->response();
    }

    public function exportYearlyAnalyticSales(Request $request)
    {
        try {
            $dataExport = $this->yearlyAnalyticSales($request);
            $dataExport = $dataExport->getData(true);

            if (!empty($dataExport['error'])) {
                return response()->json($dataExport, 422);
            }

            $listAnalyticSale = $dataExport['data']['resource']['list_analytic_sale'];

            $customDataExport = array_map(function ($data) {
                return [
                    'Category Name' => $data['product_category_sale'],
                    'Qty'           => $data['total_category'],
                    'Display Price' => $data['display_price_sale'],
                    'Sale Price'    => $data['purchase'],
                ];
            }, $listAnalyticSale);

            $fileName = 'exports/list-yearly-analytic-sales.xlsx';
            Excel::store(new ListAnalyticSalesExport($customDataExport), $fileName, 'public');

            $fileUrl = Storage::disk('public')->url($fileName);
            $resource = new ResponseResource('true', 'File export berhasil di buat!', $fileUrl);
        } catch (\Exception $e) {
            $resource = new ResponseResource('false', 'Gagal membuat file export!', $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
        return $resource;
    }

    public function analyticSlowMoving(Request $request)
    {

        try {
            $validator = Validator::make($request->all(), [
                'week' => 'nullable|integer', // Validasi untuk memastikan input 'week' adalah integer atau null
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid input format. Week should be an integer.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Ambang batas 4 minggu untuk produk menjadi kadaluarsa
            $expirationThreshold = 4;

            // Ambil input minggu dari user
            $inputWeek = $request->input('week', null);

            // Query produk kadaluarsa
            $queryProductExpired = New_product::selectRaw('
                    new_category_product as category_product,
                    COUNT(new_category_product) as total_category,
                    FLOOR(DATEDIFF(NOW(), created_at) / 7) - 4 as weeks_expired,
                    DATEDIFF(NOW(), created_at) % 7 as days_expired
                ')
                ->where('new_status_product', 'expired');

            $queryListProductExpired = New_product::selectRaw("
                    new_barcode_product,
                    new_name_product,
                    new_price_product,
                    new_quantity_product,
                    FLOOR(DATEDIFF(NOW(), created_at) / 7) - 4 as weeks_expired,
                    DATEDIFF(NOW(), created_at) % 7 as days_expired
                ")
                ->where('new_status_product', 'expired');

            $totalExpiredProduct = $queryListProductExpired->count();

            // Jika input minggu diberikan, sesuaikan filter untuk rentang waktu tersebut
            if ($inputWeek !== null) {
                $startDate = Carbon::now()->subWeeks($inputWeek + $expirationThreshold);
                $endDate = Carbon::now()->subWeeks($expirationThreshold + ($inputWeek - 1));

                $queryProductExpired->whereBetween('created_at', [$startDate, $endDate]);
                $queryListProductExpired->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Eksekusi query
            $expiredProductCategories = $queryProductExpired->groupBy('category_product', 'created_at')->get();
            $listExpiredProduct = $queryListProductExpired->get();

            return new ResponseResource(true, "Data of expired products", [
                'total_expired_product' => $totalExpiredProduct,
                'expired_product_categories' => $expiredProductCategories,
                'list_expired_product' => $listExpiredProduct,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function productExpiredExport(Request $request)
    {
        try {
            // Ambil input dari user
            $inputWeek = $request->input('week');

            // Produk dianggap expired setelah 4 minggu
            $expirationThreshold = 4;

            // Query untuk mendapatkan produk yang sudah expired
            $queryListProductExpired = New_product::selectRaw("
            new_barcode_product AS barcode_product,
            new_name_product AS name_product,
            new_price_product AS price_product,
            new_quantity_product AS qty_product,
            FLOOR(DATEDIFF(NOW(), created_at) / 7) - $expirationThreshold AS weeks_expired,
            DATEDIFF(NOW(), created_at) % 7 AS days_expired
        ")
                ->where('new_status_product', 'expired');

            if ($inputWeek !== null) {
                $startDate = Carbon::now()->subWeeks($inputWeek + $expirationThreshold);
                $endDate = Carbon::now()->subWeeks($expirationThreshold + ($inputWeek - 1));

                $queryListProductExpired->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Ambil data dalam bentuk collection
            $ListProductExpired = $queryListProductExpired->get();

            // Buat collection yang sudah di-custom
            $customProductExpired = $ListProductExpired->map(function ($product) {
                // Gabungkan weeks_expired dan days_expired menjadi satu string
                $expiredDate = "{$product->weeks_expired} minggu {$product->days_expired} hari";

                return [
                    'Barcode' => $product->barcode_product,
                    'Nama Produk' => $product->name_product,
                    'Harga' => $product->price_product,
                    'Qty' => $product->qty_product,
                    'Lama Expired' => $expiredDate,
                ];
            });
            $fileName = 'exports/expired-product.xlsx';
            Excel::store(new ProductExpiredExport($customProductExpired), $fileName, 'public');

            $fileUrl = Storage::disk('public')->url($fileName);
            $resource = new ResponseResource('true', 'File export berhasil di buat!', $fileUrl);
        } catch (\Exception $e) {
            $resource = new ResponseResource('false', 'Gagal membuat file export!', $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
        return $resource;
    }
}
