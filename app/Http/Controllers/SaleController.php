<?php

namespace App\Http\Controllers;

use App\Exports\ProductSale;
use App\Exports\ProductSaleMonth;
use App\Http\Resources\ResponseResource;
use App\Models\Bundle;
use App\Models\Buyer;
use App\Models\New_product;
use App\Models\Sale;
use App\Models\SaleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId = auth()->id();

        $allSales = Sale::where('status_sale', 'proses')->where('user_id', $userId)->get();

        $totalSale = $allSales->sum('product_price_sale');

        $sale = Sale::where('status_sale', 'proses')->where('user_id', $userId)->latest()->paginate(50);

        $saleDocument = SaleDocument::where('status_document_sale', 'proses')->where('user_id', $userId)->first();
        if ($saleDocument == null) {
            $codeDocumentSale = codeDocumentSale($userId);
            $saleBuyerName = '';
            $saleBuyerId = '';
        } else {
            $codeDocumentSale = $saleDocument->code_document_sale;
            $saleBuyerName = $saleDocument->buyer_name_document_sale;
            $saleBuyerId = $saleDocument->buyer_id_document_sale;
        }

        $data = [
            'code_document_sale' => $codeDocumentSale,
            'sale_buyer_name' => $saleBuyerName,
            'sale_buyer_id' => $saleBuyerId,
            'total_sale' => $totalSale,
        ];

        $data += $sale->toArray();

        $resource = new ResponseResource(true, "list data sale", $data);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();

        $saleDocument = SaleDocument::where('status_document_sale', 'proses')->where('user_id', $userId)->first();

        $validator = Validator::make(
            $request->all(),
            [
                'new_discount_sale' => 'nullable|numeric',
                'sale_barcode' => 'required',
                'buyer_id' => 'required|numeric',
            ]
        );

        if ($saleDocument) {
            $request['new_discount_sale'] = (float) $request->new_discount_sale;
            $validator->sometimes('new_discount_sale', ['required', 'numeric', 'in:' . $saleDocument->new_discount_sale], function () use ($saleDocument) {
                return $saleDocument && !is_null($saleDocument->new_discount_sale);
            });
        }

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            // $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))->where('status_sale', 'proses')->first();
            // if ($productSale) {
            //     $saleDocumentCheck = SaleDocument::where('code_document_sale', $productSale->code_document_sale)->first();
            //     if ($saleDocumentCheck && $saleDocumentCheck->buyer_id_document_sale == $request->input('buyer_id')) {
            //         return new ResponseResource(false, "Data sudah dimasukkan!", $productSale);
            //     }
            // }

            $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))->first();
            if ($productSale) {
                $resource = new ResponseResource(false, "Data sudah dimasukkan!", $productSale);
                return $resource->response()->setStatusCode(422);
            }

            $buyer = Buyer::find($request->buyer_id);
            if (!$buyer) {
                return (new ResponseResource(false, "Data Buyer tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            $newProduct = New_product::where('new_barcode_product', $request->sale_barcode)->first();
            $bundle = Bundle::where('barcode_bundle', $request->sale_barcode)->first();

            if ($newProduct) {
                $data = [
                    $newProduct->new_name_product,
                    $newProduct->new_category_product,
                    $newProduct->new_barcode_product,
                    $newProduct->display_price,
                    $newProduct->new_price_product,
                    $newProduct->new_discount,
                    $newProduct->old_price_product,
                    $newProduct->code_document,
                    $newProduct->type,
                    $newProduct->old_barcode_product

                ];
            } elseif ($bundle) {
                $data = [
                    $bundle->name_bundle,
                    $bundle->category,
                    $bundle->barcode_bundle,
                    $bundle->total_price_custom_bundle,
                    $bundle->total_price_bundle,
                ];
            } else {
                return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            if (!$saleDocument) {
                $saleDocumentRequest = [
                    'code_document_sale' => codeDocumentSale($userId),
                    'buyer_id_document_sale' => $buyer->id,
                    'buyer_name_document_sale' => $buyer->name_buyer,
                    'buyer_phone_document_sale' => $buyer->phone_buyer,
                    'buyer_address_document_sale' => $buyer->address_buyer,
                    'new_discount_sale' => $request->new_discount_sale,
                    'total_product_document_sale' => 0,
                    'total_old_price_document_sale' => 0,
                    'total_price_document_sale' => 0,
                    'total_display_document_sale' => 0,
                    'status_document_sale' => 'proses',
                    'cardbox_qty' => 0,
                    'cardbox_unit_price' => 0,
                    'cardbox_total_price' => 0,
                    'voucher' => 0,
                ];

                $createSaleDocument = (new SaleDocumentController)->store(new Request($saleDocumentRequest));
                if ($createSaleDocument->getStatusCode() != 201) {
                    return $createSaleDocument;
                }
                $saleDocument = $createSaleDocument->getData()->data->resource;
            }

            //kondisin jika terdapat inputan diskon
            if ($saleDocument->new_discount_sale != 0) {
                $newDiscountSale = $saleDocument->new_discount_sale;
                $discountWithPercent = $newDiscountSale / 100;
                $productPriceSale = $data[6] - $data[6] * $discountWithPercent ?? $data[4] - $data[4] * $discountWithPercent;
                $displayPrice = $productPriceSale;
                $totalDiscountSale = $data[6] * $discountWithPercent ?? $data[4] * $discountWithPercent;
            } else {
                $newDiscountSale = $data[5] ?? null;
                $productPriceSale = $data[3];
                $totalDiscountSale = $data[4] - $data[3];
                $displayPrice = $data[3];
            }

            $sale = Sale::create(
                [
                    'user_id' => auth()->id(),
                    'code_document_sale' => $saleDocument->code_document_sale,
                    'product_name_sale' => $data[0],
                    'product_category_sale' => $data[1],
                    'product_barcode_sale' => $data[2],
                    'product_old_price_sale' => $data[6] ?? $data[4],
                    'product_price_sale' => $productPriceSale,
                    'product_qty_sale' => 1,
                    'status_sale' => 'proses',
                    'total_discount_sale' => $totalDiscountSale,
                    'new_discount_sale' => $newDiscountSale,
                    'display_price' => $displayPrice,
                    'code_document' => $data[7] ?? null,
                    'type' => $data[8],
                    'old_barcode_product' => $data[9] ?? null
                ]
            );

            DB::commit();
            return new ResponseResource(true, "data berhasil di tambahkan!", $sale);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage()))->response()->setStatusCode(500);
        }
    }

    public function show(Sale $sale)
    {
        $resource = new ResponseResource(true, "data sale", $sale);
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sale $sale)
    {
        try {
            $checkSale = Sale::where('status_sale', 'proses')->where('user_id', auth()->id())->first();
            if ($checkSale == null) {
                return response()->json(['status' => false, 'message' => 'sale not found'], 404);
            }

            $allSale = Sale::where('code_document_sale', $sale->code_document_sale)
                ->where('user_id', auth()->id())
                ->where('status_sale', 'proses')
                ->get();

            if ($allSale->count() <= 1) {
                $saleDocument = SaleDocument::where('code_document_sale', $sale->code_document_sale)->where('user_id', auth()->id())->first();
                $saleDocument->delete();
            }

            $sale->delete();
            $resource = new ResponseResource(true, "data berhasil di hapus", $sale);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }

    public function products()
    {
        $searchQuery = request()->has('q') ? request()->q : null;

        $productSaleBarcodes = Sale::where('status_sale', 'proses')->pluck('product_barcode_sale')->toArray();

        $newProductsQuery = New_product::whereNotIn('new_barcode_product', $productSaleBarcodes)
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereNotNull('new_category_product')
            ->where('new_status_product', '!=', 'sale')
            ->select('new_barcode_product as barcode', 'new_name_product as name', 'new_category_product as category', 'created_at as created_date');

        if ($searchQuery) {
            $newProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'like', '%' . $searchQuery . '%');
            });
        }

        $bundleQuery = Bundle::select('barcode_bundle as barcode', 'name_bundle as name', 'category', 'created_at as created_date');

        if ($searchQuery) {
            $bundleQuery->where(function ($query) use ($searchQuery) {
                $query->where('barcode_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('name_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('category', 'like', '%' . $searchQuery . '%');
            });
        }

        $products = $newProductsQuery->union($bundleQuery)
            ->orderBy('created_date', 'desc')
            ->paginate(10);

        $resource = new ResponseResource(true, "list data product", $products);
        return $resource->response();
    }

    public function updatePriceSale(Request $request, Sale $sale)
    {

        $validator = Validator::make($request->all(), [
            'product_price_sale' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            DB::beginTransaction();
            // $product = New_product::where('new_barcode_product', $sale->product_barcode_sale)->first();
            // $product->new_price_product = $request->input('product_price_sale');
            // $product->save();
            $persentage_diskon = $request->input('product_price_sale');
            $current_price = $sale->product_price_sale;
            $diskon = $current_price - ($current_price * ($persentage_diskon / 100));
            $sale->product_price_sale = $diskon;
            $sale->save();

            DB::commit();
            return new ResponseResource(true, "data berhasil di update", $sale);
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(false, "Data gagal ditambahkan", $e->getMessage()))
                ->setStatusCode(500);
        }
    }

    public function livePriceUpdates(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'update_price_sale' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        $sale->product_price_sale = $request->input('update_price_sale');
        $sale->save();
        return new ResponseResource(true, "data berhasil di update", $sale);
    }

    public function getCategoryNull()
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $userHeaders = [
            'id',
            'user_id',
            'code_document_sale',
            'product_name_sale',
            'product_category_sale',
            'product_barcode_sale',
            'product_old_price_sale',
            'product_price_sale',
            'product_qty_sale',
            'status_sale',
            'total_discount_sale',
            'created_at',
            'updated_at',
            'new_discount',
            'display_price',
        ];

        $columnIndex = 1;
        foreach ($userHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        $rowIndex = 2; // Mulai dari baris kedua

        $sales = DB::table('sales')
            ->whereRaw("TRIM(`product_category_sale`) = ''")
            ->get();

        foreach ($sales as $data) {
            $columnIndex = 1;

            // Menuliskan data user ke sheet
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->id);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->user_id);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->code_document_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_name_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_category_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_barcode_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_old_price_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_price_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_qty_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->status_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->total_discount_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->created_at);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->updated_at);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->new_discount);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->display_price);

            $rowIndex++;
        }

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'sales_category_null.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "unduh", $downloadUrl);
    }

    public function exportSale()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-staging.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductSale(Sale::class), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportSaleMonth()
    {
        try {
            $fileName = 'pr.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);
    
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }
    
            Excel::store(new ProductSaleMonth(Sale::class, 11), $publicPath . '/' . $fileName, 'public');
    
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);
    
            return response()->json([
                'status' => true,
                'message' => 'File berhasil diunduh',
                'download_url' => $downloadUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengunduh file: ' . $e->getMessage(),
                'resource' => [],
            ]);
        }
    }
}
