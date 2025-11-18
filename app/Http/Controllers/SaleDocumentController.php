<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\User;
use App\Models\Buyer;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\BuyerPoint;
use App\Models\LogFinance;
use Brick\Math\BigInteger;
use App\Models\LoyaltyRank;
use App\Models\New_product;
use App\Models\BuyerLoyalty;
use App\Models\Notification;
use App\Models\SaleDocument;
use Illuminate\Http\Request;
use GuzzleHttp\Psr7\Response;
use App\Models\StagingProduct;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\ResponseResource;
use App\Services\Bulky\ApiRequestService;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SaleDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $saleDocuments = SaleDocument::with('user:id,name', 'buyer:id,point_buyer')->where('status_document_sale', 'selesai')->latest();
        if ($query) {
            $saleDocuments = $saleDocuments->where(function ($data) use ($query) {
                $data->where('code_document_sale', 'LIKE', '%' . $query . '%')
                    ->orWhere('buyer_name_document_sale', 'LIKE', '%' . $query . '%');
            });
        }
        $saleDocuments = $saleDocuments->paginate(11);
        $resource = new ResponseResource(true, "list document sale", $saleDocuments);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'code_document_sale' => 'required|unique:sale_documents',
                'buyer_name_document_sale'  => 'required',
                'total_product_document_sale' => 'required',
                'total_price_document_sale' => 'required',
                'voucher' => 'numeric|nullable'
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        try {
            $request['user_id'] = auth()->id();
            $saleDocument = SaleDocument::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $saleDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }
        return $resource->response();
    }

    /**
     * Display the specified resource.  
     */
    public function show($id)
    {
        $saleDocument = SaleDocument::with(['sales', 'user', 'buyer'])->findOrFail($id);
        $buyer = Buyer::with(['buyerLoyalty.rank'])->find($saleDocument->buyer_id_document_sale);

        // Gunakan helper function untuk mendapatkan rank info SAMPAI transaksi ini
        // Passing created_at untuk mendapatkan state pada saat transaksi ini terjadi
        $rankInfo = LoyaltyService::getCurrentRankInfo(
            $saleDocument->buyer_id_document_sale,
            $saleDocument->created_at
        );
        
        // transactionCount dari getCurrentRankInfo adalah count SETELAH transaksi ini diproses
        $transactionCountAfter = $rankInfo['transaction_count'];
        $currentRankAfter = $rankInfo['current_rank'];
        $expireDate = $rankInfo['expire_date'];

        // Untuk menampilkan rank SAAT transaksi terjadi (BEFORE processing)
        // Kita perlu tahu rank berdasarkan count SEBELUM transaksi ini
        $transactionCountBefore = max(0, $transactionCountAfter - 1);
        
        // Cari rank SAAT transaksi berdasarkan count sebelum transaksi
        $rankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '<=', $transactionCountBefore)
            ->orderBy('min_transactions', 'desc')
            ->first();
        
        // Jika tidak ada rank yang cocok, gunakan New Buyer
        if (!$rankAtTransaction) {
            $rankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', 0)->first();
        }
        
        // Cari next rank berdasarkan count saat transaksi
        $nextRankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '>', $transactionCountBefore)
            ->orderBy('min_transactions', 'asc')
            ->first();
        
        
        $buyerData = [
            'id' => $buyer->id,
            'point_buyer' => $buyer->point_buyer,
            'rank' => $rankAtTransaction->rank ?? null, // Rank SAAT transaksi
            'next_rank' => $nextRankAtTransaction ? $nextRankAtTransaction->rank : null,
            'transaction_next' => $nextRankAtTransaction ? max(0, $nextRankAtTransaction->min_transactions - $transactionCountBefore) : 0,
            'percentage_discount' => $rankAtTransaction->percentage_discount ?? 0, // Discount yang dipakai saat transaksi
            'current_transaction' => $transactionCountAfter, // Ini transaksi ke berapa (setelah diproses)
            'expire_date' => $expireDate ? $expireDate->format('Y-m-d H:i:s') : null,
        ];

        // Siapkan resource untuk response
        $resource = [
            'id' => $saleDocument->id,
            'user_id' => $saleDocument->user_id,
            'code_document_sale' => $saleDocument->code_document_sale,
            'buyer_id_document_sale' => $saleDocument->buyer_id_document_sale,
            'buyer_name_document_sale' => $saleDocument->buyer_name_document_sale,
            'buyer_phone_document_sale' => $saleDocument->buyer_phone_document_sale,
            'buyer_address_document_sale' => $saleDocument->buyer_address_document_sale,
            'buyer_point_document_sale' => $saleDocument->buyer_point_document_sale,
            'new_discount_sale' => $saleDocument->new_discount_sale,
            'type_discount' => $saleDocument->type_discount,
            'total_product_document_sale' => $saleDocument->total_product_document_sale,
            'total_old_price_document_sale' => $saleDocument->total_old_price_document_sale,
            'total_price_document_sale' => $saleDocument->total_price_document_sale,
            'total_display_document_sale' => $saleDocument->total_display_document_sale,
            'status_document_sale' => $saleDocument->status_document_sale,
            'cardbox_qty' => $saleDocument->cardbox_qty,
            'cardbox_unit_price' => $saleDocument->cardbox_unit_price,
            'cardbox_total_price' => $saleDocument->cardbox_total_price,
            'created_at' => $saleDocument->created_at,
            'updated_at' => $saleDocument->updated_at,
            'voucher' => $saleDocument->voucher,
            'code_document' => $saleDocument->code_document,
            'approved' => $saleDocument->approved,
            'is_tax' => $saleDocument->is_tax,
            'tax' => $saleDocument->tax,
            'price_after_tax' => $saleDocument->price_after_tax,
            'grand_total' => $saleDocument->grand_total,
            'sales' => $saleDocument->sales,
            'user' => $saleDocument->user,
            'buyer' => $buyerData,
        ];

        return new ResponseResource(true, "data document sale", $resource);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SaleDocument $saleDocument)
    {
        $validator = Validator::make($request->all(), [
            'cardbox_qty' => 'required|numeric',
            'cardbox_unit_price' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        if (
            $request->cardbox_qty == $saleDocument->cardbox_qty &&
            $request->cardbox_unit_price == $saleDocument->cardbox_unit_price
        ) {
            $resource = new ResponseResource(false, "Data tidak ada yang berubah!", $saleDocument->load('sales', 'user'));
        } else {
            // Hitung total cardbox baru
            $newCardboxTotal = $request->cardbox_qty * $request->cardbox_unit_price;
            
            // Grand total = total_price_document_sale + cardbox
            $grandTotal = $saleDocument->total_price_document_sale + $newCardboxTotal;
            
            // Hitung price_after_tax
            $priceAfterTax = $grandTotal;
            
            // Jika ada tax, tambahkan tax ke grand total
            if ($saleDocument->is_tax == 1 && $saleDocument->tax !== null && $saleDocument->tax > 0) {
                $taxAmount = $grandTotal * ($saleDocument->tax / 100);
                $priceAfterTax = $grandTotal + $taxAmount;
            }
            
            $saleDocument->update([
                'cardbox_qty' => $request->cardbox_qty,
                'cardbox_unit_price' => $request->cardbox_unit_price,
                'cardbox_total_price' => $newCardboxTotal,
                'price_after_tax' => $priceAfterTax,
            ]);

            $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument->load('sales', 'user'));
        }

        return $resource->response();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SaleDocument $saleDocument)
    {
        try {
            $saleDocument->delete();
            $resource = new ResponseResource(true, "data berhasil di hapus!", $saleDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "data gagal di hapus!", $e->getMessage());
        }
        return $resource->response();
    }

    public function saleFinish(Request $request)
    {
        try {
            DB::beginTransaction();

            // Validasi user terautentikasi
            $user = $request->user();
            if (!$user) {
                throw new Exception("User tidak terautentikasi!");
            }

            $userId = $user->id;
            $saleDocument = SaleDocument::where('status_document_sale', 'proses')
                ->where('user_id', $userId)
                ->first();

            if ($saleDocument == null) {
                throw new Exception("Data sale belum dibuat!");
            }

            $validator = Validator::make($request->all(), [
                'voucher' => 'nullable|numeric',
                'cardbox_qty' => 'nullable|numeric|required_with:cardbox_unit_price',
                'cardbox_unit_price' => 'nullable|numeric|required_with:cardbox_qty',
                'tax' => 'nullable|numeric|min:0|max:50',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
            }

            $sales = Sale::where('code_document_sale', $saleDocument->code_document_sale)->get();

            // Validasi ada sales
            if ($sales->isEmpty()) {
                throw new Exception("Tidak ada produk dalam sale document {$saleDocument->code_document_sale}!");
            }

            // Inisialisasi approved dokumen sebagai '0'
            $approved = '0';
            if ($request->filled('voucher')) {
                foreach ($sales as $sale) {
                    if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
                        // Update hanya sales yang memenuhi kondisi
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        // Sales yang tidak memenuhi kondisi tetap '0'
                        $sale->update(['approved' => '0']);
                    }
                }
            } else {
                foreach ($sales as $sale) {
                    if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        $sale->update(['approved' => '0']);
                        $approved = '0';
                    }
                }
            }
            if ($request->input('voucher') !== '0') {
                $approved = '1';
            }
            if ($saleDocument->new_discount_sale > 0) {
                $approved = '1';
            }
            // Update dokumen dan buat notifikasi jika ada sales yang approved
            if ($approved === '1') {
                // Validasi user untuk notifikasi
                if (!$user || !$user->id) {
                    throw new Exception("User ID tidak valid untuk membuat notifikasi!");
                }

                // Validasi saleDocument untuk notifikasi
                if (!$saleDocument || !$saleDocument->id) {
                    throw new Exception("Sale Document ID tidak valid untuk membuat notifikasi!");
                }

                Notification::create([
                    'user_id' => $userId,
                    'notification_name' => 'approve discount sale',
                    'status' => 'sale',
                    'role' => 'Spv',
                    'external_id' => $saleDocument->id
                ]);

                $saleDocument->update(['approved' => '1']);
            }

            $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');

            $totalProductPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_price_sale');

            $totalProductPriceSale = $request['voucher'] ? $totalProductPriceSale - $request['voucher'] : $totalProductPriceSale;

            $totalProductOldPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_old_price_sale');

            $totalCardBoxPrice = $request->cardbox_qty * $request->cardbox_unit_price;

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            // Validasi buyer ditemukan
            if (!$buyer) {
                throw new Exception("Buyer dengan ID {$saleDocument->buyer_id_document_sale} tidak ditemukan!");
            }

            $rankDiscount = LoyaltyService::processLoyalty($buyer->id, $totalDisplayPrice);

            $grandTotal = $totalProductPriceSale + $totalCardBoxPrice;

            // kondisi jika ada dan tidak ada pajak / ppn tapi check is_tax dulu cuy
            $tax = 0;
            if ($request->input('is_tax') != 0 || $request->input('is_tax') != null) {
                if ($request->input('tax') == null) {
                    return (new ResponseResource(false, "Input tidak valid!", "Tax harus diisi jika is_tax di centang!"))->response()->setStatusCode(422);
                }

                $tax = $request->input('tax');
                $taxPrice = $grandTotal * ($tax / 100);
                $priceAfterTax = $grandTotal + $taxPrice;
            } else {
                $tax = 0;
                $priceAfterTax = $grandTotal;
            }

            // // Ambil barcodes dari $sales
            $productBarcodes = $sales->pluck('product_barcode_sale');

            // // Hapus semua New_product yang sesuai
            // New_product::whereIn('new_barcode_product', $productBarcodes)->delete();

            // // Hapus semua staging yang sesuai
            // StagingProduct::whereIn('new_barcode_product', $productBarcodes)->delete();

            // Update semua Bundle yang sesuai menjadi 'sale'
            Bundle::whereIn('barcode_bundle', $productBarcodes)->update(['product_status' => 'sale']);

            // Batch update status pada $sales
            $sales->each->update(['status_sale' => 'selesai']);

            $earnPoint =  floor($totalProductPriceSale / 1000);

            $saleDocument->update([
                'buyer_point_document_sale' => $earnPoint,
                'total_product_document_sale' => count($sales),
                'total_old_price_document_sale' => $totalProductOldPriceSale,
                'total_price_document_sale' => $totalProductPriceSale,
                'total_display_document_sale' => $totalDisplayPrice,
                'status_document_sale' => 'selesai',
                'cardbox_qty' => $request->cardbox_qty ?? 0,
                'cardbox_unit_price' => $request->cardbox_unit_price ?? 0,
                'cardbox_total_price' => $totalCardBoxPrice ?? 0,
                'voucher' => $request->input('voucher'),
                'approved' => $approved,
                'is_tax' => $request->input('tax') ? 1 : 0,
                'tax' => $tax,
                'price_after_tax' => $priceAfterTax,
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $saleDocumentCountWithBuyerId = SaleDocument::where('buyer_id_document_sale', $buyer->id)->count();

            if ($saleDocumentCountWithBuyerId == 2 || $saleDocumentCountWithBuyerId == 3) {
                $typeBuyer = 'Repeat';
            } else if ($saleDocumentCountWithBuyerId > 3) {
                $typeBuyer = 'Reguler';
            }

            $buyer->update([
                'type_buyer' => $typeBuyer ?? "Biasa",
                'amount_transaction_buyer' => $buyer->amount_transaction_buyer + 1,
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
                'point_buyer' => $buyer->point_buyer + $earnPoint,
            ]);

            // Validasi buyer untuk BuyerPoint
            if (!$buyer || !$buyer->id) {
                throw new Exception("Buyer ID tidak valid untuk membuat buyer point!");
            }

            $buyerPoint = BuyerPoint::create([
                'buyer_id' => $buyer->id,
                'earn' => $earnPoint,
                'year' => Carbon::now()->year,
            ]);

            $productBulky =  ApiRequestService::post('/products/create', [
                'images' => null,
                // 'wms_id' => $request->wms_id ?? null,
                'name' => 'Palet ' . $saleDocument->code_document_sale,
                'price' => $saleDocument->total_price_document_sale,
                'price_before_discount' => $saleDocument->total_old_price_document_sale,
                'total_quantity' => $saleDocument->total_product_document_sale,
                'pdf_file' => null,
                'description' => 'Transaksi penjualan dari WMS dengan code ' . $saleDocument->code_document_sale,
                'is_active' => false,
                // 'warehouse_id' => null,
                // 'product_category_id' => $request->product_category_id,
                // 'brand_ids' => null,
                // 'product_condition_id' => $request->product_condition_id,
                // 'product_status_id' => $request->product_status_id,
                'is_sold' => true,
            ]);

            if ($productBulky['error'] ?? false) {
                throw new Exception($productBulky['error']);
            }

            logUserAction($request, $request->user(), "outbound/sale/kasir", "Menekan tombol sale", $saleDocument->code_document_sale);

            DB::commit();
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument->load('sales', 'user', 'buyer:id,point_buyer'));
        } catch (\Exception $e) {
            DB::rollBack();

            // Log error dengan detail lengkap
            Log::error('Error in saleFinish method:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_data' => $request->all()
            ]);

            $resource = new ResponseResource(false, "Data gagal disimpan!", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $resource->response()->setStatusCode(500);
        }
        return $resource->response();
    }

    public function addProductSaleInDocument(Request $request)
    {
        DB::beginTransaction();
        // $userId = auth()->id();

        $validator = Validator::make(
            $request->all(),
            [
                'sale_barcode' => 'required',
                'sale_document_id' => 'required|numeric',
                'type_discount' => 'nullable|in:old,new'
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            $saleDocument = SaleDocument::find($request->sale_document_id);

            if (!$saleDocument) {
                return (new ResponseResource(false, "sale_document_id tidak di temukan!", []))->response()->setStatusCode(404);
            }

            $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))->first();
            if ($productSale) {
                $resource = new ResponseResource(false, "Data sudah dimasukkan!", $productSale);
                return $resource->response()->setStatusCode(422);
            }

            $newProduct = New_product::where('new_barcode_product', $request->sale_barcode)->first();
            $staging = StagingProduct::where('new_barcode_product', $request->sale_barcode)->first();
            $bundle = Bundle::where('barcode_bundle', $request->sale_barcode)->first();

            if (!$newProduct && !$bundle && !$staging) {
                return (new ResponseResource(false, "Data Buyer tidak ditemukan!", []))->response()->setStatusCode(404);
            }

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
                $newProduct->update(['new_status_product' => 'sale']);
            } else if ($staging) {
                $data = [
                    $staging->new_name_product,
                    $staging->new_category_product,
                    $staging->new_barcode_product,
                    $staging->display_price,
                    $staging->new_price_product,
                    $staging->new_discount,
                    $staging->old_price_product,
                    $staging->code_document,
                    $staging->type,
                    $staging->old_barcode_product
                ];
                $staging->update(['new_status_product' => 'sale']);
            } elseif ($bundle) {
                $data = [
                    $bundle->name_bundle,
                    $bundle->category,
                    $bundle->barcode_bundle,
                    $bundle->total_price_custom_bundle,
                    $bundle->total_price_bundle,
                    $bundle->type
                ];
                $bundle->update(['product_status' => 'sale']);
            } else {
                return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            // Ambil data transaksi awal
            $productAdd = 0;
            $priceAfterDiscount = 0;
            $productAddDiscount = 0;

            if ($saleDocument->new_discount_sale > 0) {
                if ($saleDocument->type_discount == 'new') {
                    $productAdd = $data[4];
                    // Hitung diskon 
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                } else if ($saleDocument->type_discount == 'old') {
                    $productAdd = $data[6];
                    // Hitung diskon 
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                } else {
                    $productAdd = $data[4];
                    // Hitung diskon 
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                }
            } else {
                $productAdd =  $data[4];
                // Hitung diskon 
                $discount = $saleDocument->new_discount_sale;
                $productAddDiscount = $productAdd * (1 - ($discount / 100));
                $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
            }

            // Tambahkan biaya karton box
            $karton = $saleDocument->cardbox_total_price;
            $priceAfterKarton = $priceAfterDiscount + $karton;

            // Hitung pajak 
            $tax = $priceAfterKarton * ($saleDocument->tax / 100);
            // Hitung grand total
            $grandTotal = $priceAfterKarton + $tax;

            $sale = Sale::create(
                [
                    'user_id' => auth()->id(),
                    'code_document_sale' => $saleDocument->code_document_sale,
                    'product_name_sale' => $data[0],
                    'product_category_sale' => $data[1],
                    'product_barcode_sale' => $data[2],
                    'product_old_price_sale' => ceil($data[6]) ?? ceil($data[4]),
                    'product_price_sale' => ceil($productAddDiscount),
                    'product_qty_sale' => 1,
                    'status_sale' => 'selesai',
                    'total_discount_sale' => ceil($productAddDiscount),
                    'new_discount' => $saleDocument->new_discount_sale ?? NULL,
                    'display_price' => ceil($data[3]),
                    'type' => $data[8],
                    'old_barcode_product' => $data[9],
                    'type_discount' => $saleDocument->type_discount
                ]
            );

            $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');

            // Update sale document
            $saleDocument->update([
                'total_product_document_sale' => $saleDocument->total_product_document_sale + 1,  // Update jumlah produk
                'total_old_price_document_sale' => $data[6] + $saleDocument->total_old_price_document_sale, // Update harga lama
                'total_price_document_sale' => ceil($priceAfterDiscount),
                'total_display_document_sale' => ceil($totalDisplayPrice),
                'price_after_tax' => ceil($grandTotal),
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');
            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
            ]);

            DB::commit();
            return new ResponseResource(true, "data berhasil di tambahkan!", $saleDocument->load('sales', 'user'));
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage()))->response()->setStatusCode(500);
        }
    }

    public function deleteProductSaleInDocument(SaleDocument $sale_document, Sale $sale)
    {
        DB::beginTransaction();
        try {

            $allSale = Sale::where('code_document_sale', $sale_document->code_document_sale)
                ->where('status_sale', 'selesai')
                ->get();

            $priceBeforeTax = $sale_document->total_price_document_sale - $sale->product_price_sale;
            $tax = $sale_document->tax;
            $priceAfterTax = $priceBeforeTax + ($priceBeforeTax * ($tax / 100));

            $sale_document->update([
                'total_product_document_sale' => $sale_document->total_product_document_sale - 1,
                'total_old_price_document_sale' => $sale_document->total_old_price_document_sale - $sale->product_old_price_sale,
                'total_price_document_sale' => ceil($priceBeforeTax),
                'total_display_document_sale' => ceil($sale_document->total_display_document_sale - $sale->display_price),
                'price_after_tax' => ceil($priceAfterTax)
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $sale_document->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($sale_document->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            //cek apabila di dalam document sale sudah tidak ada produk sale lagi
            if ($allSale->count() <= 1) {
                $buyer->update([
                    'amount_transaction_buyer' => $buyer->amount_transaction_buyer - 1,
                ]);
                $sale_document->delete();
            }

            $sale->delete();

            $bundle = Bundle::where('barcode_bundle', $sale->product_barcode_sale)->first();
            if (!empty($bundle)) {
                $bundle->update(['product_status' => 'not sale']);
            } else {
                $lolos = json_encode(['lolos' => 'lolos']);
                New_product::insert([
                    'code_document' => $sale->code_document,
                    'old_barcode_product' => $sale->product_barcode_sale,
                    'new_barcode_product' => $sale->product_barcode_sale,
                    'new_name_product' => $sale->product_name_sale,
                    'new_quantity_product' => $sale->product_qty_sale,
                    'new_price_product' => $sale->product_old_price_sale,
                    'old_price_product' => $sale->product_old_price_sale,
                    'new_date_in_product' => $sale->created_at,
                    'new_status_product' => 'display',
                    'new_quality' => $lolos,
                    'new_category_product' => $sale->product_category_sale,
                    'new_tag_product' => null,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                    'new_discount' => 0,
                    'display_price' => $sale->product_price_sale
                ]);
            }
            $resource = new ResponseResource(true, "data berhasil di hapus", $sale_document->load('sales', 'user'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }

    public function orderIntoBulky(SaleDocument $saleDocument, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'phone_number' => 'nullable|string',
            'register_email' => 'nullable|boolean',
            'payment_type' => 'required|string|in:single_payment,split_payment',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        $orderIntoBulky = ApiRequestService::post('/wms/place-order-by-wms', [
            'email' => $request->input('email'),
            'phone_number' => $request->input('phone_number') ?? $saleDocument->buyer_phone_document_sale,
            'register_email' => $request->input('register_email') ?? false,
            'payment_type' => $request->input('payment_type'),
            'code_document_sale' => $saleDocument->code_document_sale,
        ]);

        if ($orderIntoBulky['error'] ?? false) {
            return (new ResponseResource(false, "Gagal mengirim order ke Bulky!", $orderIntoBulky['error']))->response()->setStatusCode(500);
        }

        return (new ResponseResource(true, "Order berhasil dikirim ke Bulky!", $orderIntoBulky))->response();
    }

    public function combinedReport(Request $request)
    {
        $user = auth()->user();
        $name_user = $user->name;
        $codeDocument = $request->input('code_document_sale');
        $saleDocument = SaleDocument::with('buyer:id,point_buyer')->where('code_document_sale', $codeDocument)->first();

        if (!$saleDocument) {
            return response()->json([
                'data' => null,
                'message' => 'Dokumen penjualan tidak ditemukan',
            ], 404);
        }

        $timezone = 'Asia/Jakarta';
        $currentTransactionTime = Carbon::parse($saleDocument->created_at)->timezone($timezone);

        $totalTransactionsBeforeCurrent = SaleDocument::whereDate('created_at', $currentTransactionTime->toDateString())
            ->where('created_at', '<', $currentTransactionTime)
            ->count();

        $pembeliKeBerapa = $totalTransactionsBeforeCurrent + 1;

        $categoryReport = $this->generateCategoryReport($saleDocument);
        // $barcodeReport = $this->generateBarcodeReport($saleDocument);

        // Gunakan helper function untuk mendapatkan rank info berdasarkan simulasi expired_weeks
        $rankInfo = LoyaltyService::getCurrentRankInfo(
            $saleDocument->buyer_id_document_sale,
            $saleDocument->created_at
        );
        
        $currentRank = $rankInfo['current_rank'];
        $transactionCount = $rankInfo['transaction_count'];
        $expireDate = $rankInfo['expire_date'];

        // Hitung effective count (rank yang sedang dipakai saat transaksi)
        $effectiveCount = max(0, $transactionCount - 1);
        
        // Cari next rank berdasarkan effective count (rank saat transaksi terjadi)
        $nextRankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '>', $effectiveCount)
            ->orderBy('min_transactions', 'asc')
            ->first();

        $buyerLoyalty = BuyerLoyalty::with('rank')->where('buyer_id', $saleDocument->buyer_id_document_sale)->first();
        $totalDiscountRankPrice = 0;
        
        // Hitung total diskon rank menggunakan currentRank (rank yang sedang dipakai saat transaksi)
        $percentageDiscount = $currentRank->percentage_discount ?? 0;
        
        if ($percentageDiscount > 0) {
            $totalDiscountedPrice = SaleDocument::with('sales')
                ->where('code_document_sale', $codeDocument)
                ->get()
                ->sum(function ($saleDocument) use ($percentageDiscount) {
                    return $saleDocument->sales->sum(function ($sale) use ($percentageDiscount) {
                        $discountAmount = $sale->display_price * ($percentageDiscount / 100);
                        return $discountAmount;
                    });
                });

            if ($totalDiscountedPrice > 0) {
                $totalDiscountRankPrice = $totalDiscountedPrice;
            }
        }

        return response()->json([
            'data' => [
                'name_user' => $name_user,
                'transactions_today' => $pembeliKeBerapa,
                'category_report' => $categoryReport,
                // 'NameBarcode_report' => $barcodeReport,
            ],
            'message' => 'Laporan penjualan',
            'buyer' => $saleDocument,
            'buyer_loyalty' => [
                'rank' => $currentRank->rank ?? null,
                'next_rank' => $nextRankAtTransaction ? $nextRankAtTransaction->rank : null,
                'transaction_next' => $nextRankAtTransaction ? max(0, $nextRankAtTransaction->min_transactions - $effectiveCount) : 0,
                'percentage_discount' => $percentageDiscount,
                'expired_rank' => $expireDate ? $expireDate->format('Y-m-d H:i:s') : null,
                'current_transaction' => $transactionCount,
                'total_disc_rank' => $totalDiscountRankPrice ?? null, // Total diskon untuk seluruh barang
            ],
        ]);
    }

    private function generateCategoryReport($saleDocument)
    {
        $totalPrice = 0;
        $oldPrice = 0;
        $categoryReport = [];
        $categories = collect();

        foreach ($saleDocument->sales as $sale) {
            $category = Category::where('name_category', $sale->product_category_sale)->first();
            if ($category) {
                $categories->push($category);
            }
        }
        if ($saleDocument->sales->count() > 0) {
            $groupedSales = $saleDocument->sales->groupBy(function ($sale) {
                return $sale->product_category_sale ? strtoupper($sale->product_category_sale) : 'Unknown';
            });

            foreach ($groupedSales as $categoryName => $group) {
                $totalPricePerCategory = $group->sum(function ($sale) {
                    return $sale->product_qty_sale * $sale->product_price_sale;
                });

                $PriceBeforeDiscount = $group->sum(function ($sale) {
                    return $sale->product_old_price_sale;
                });
                $oldPrice += $PriceBeforeDiscount;
                $totalPrice += $totalPricePerCategory;

                // Menemukan kategori dari koleksi secara manual
                $category = null;
                foreach ($categories as $cat) {
                    if ($cat->name_category === $categoryName) {
                        $category = $cat;
                        break;
                    }
                }

                $categoryReport[] = [
                    'category' => $categoryName,
                    'total_quantity' => $group->sum('product_qty_sale'),
                    'total_price' => ceil($totalPricePerCategory),
                    'before_discount' => ceil($PriceBeforeDiscount),
                    'total_discount' => $category ? $category->discount_category : null,
                ];
            }
        }

        return ["category_list" => $categoryReport, 'total_harga' => ceil($totalPrice), 'total_price_before_discount' => ceil($oldPrice)];
    }

    private function generateBarcodeReport($saleDocument)
    {
        $report = [];
        $totalPrice = 0;

        foreach ($saleDocument->sales as $index => $sale) {
            $productName = $sale->product_name_sale;
            $productBarcode = $sale->product_barcode_sale;
            $productPrice = $sale->product_price_sale;
            $productQty = $sale->product_qty_sale;

            $subtotalPrice = $productPrice * $productQty;

            $report[] = [
                $index + 1,
                $productName,
                $productBarcode,
                $subtotalPrice,
            ];

            $totalPrice += $subtotalPrice;
        }

        $report[] = ['Total Harga', $totalPrice];

        return $report;
    }

    public function approvedDocument($id_sale_document)
    {
        $document_sale = SaleDocument::where('id', $id_sale_document)
            ->with(['sales' => function ($query) {
                $query->whereColumn('product_price_sale', '<', 'display_price');
            }])->first();

        if (!$document_sale) {
            return (new ResponseResource(false, "Sale document tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $document_sale->approved = '2';
        $document_sale->save();

        Sale::where('code_document_sale', $document_sale->code_document_sale)
            ->whereColumn('product_price_sale', '<', 'display_price')
            ->update(['approved' => '2']);

        $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
        if (!$notif) {
            return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
        }
        $notif->update(['approved' => '2']);

        return new ResponseResource(true, "Approved berhasil di approve", $document_sale->code_document_sale);
    }

    public function approvedProduct($id_sale)
    {
        $sale = Sale::where('id', $id_sale)->where('approved', '1')->first();

        if (!$sale) {
            return (new ResponseResource(false, "Product tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $sale->approved = '2';
        $sale->save();
        $response = [
            'code_document_sale' => $sale->code_document_sale,
            'product_name_sale' => $sale->product_name_sale,
            'product_category_sale' => $sale->product_category_sale,
            'product_barcode_sale' => $sale->product_barcode_sale,
            'product_price_sale' => $sale->product_price_sale,
            'display_price' => $sale->display_price,
            'approved' => $sale->approved
        ];

        return new ResponseResource(true, "berhasil approve", $response);
    }

    public function rejectProduct($id_sale)
    {
        // Cek apakah produk ini sedang dalam status diskon
        $sale = Sale::where('id', $id_sale)
            ->where(function ($query) {
                $query->where('approved', '1')
                    ->orWhere('approved', '2');
            })
            ->first();

        if (!$sale) {
            return (new ResponseResource(false, "Product tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $saleDocument = SaleDocument::where('code_document_sale', $sale->code_document_sale)->first();

        // Simpan total harga lama sebelum update
        $oldTotalPrice = $saleDocument->total_price_document_sale;

        // Update sale dengan harga asli dan ubah status approved jadi 0
        $sale->approved = '0';
        $sale->product_price_sale = $sale->display_price;
        $sale->save();

        // Hitung total baru:
        // 1. Total dari produk yang masih berstatus diskon (approved 1 atau 2)
        $totalDiscountedPrice = Sale::where('code_document_sale', $sale->code_document_sale)
            ->where(function ($query) {
                $query->where('approved', '1')
                    ->orWhere('approved', '2');
            })
            ->sum('product_price_sale');

        // 2. Total dari produk yang tidak ada diskon (approved 0)
        $totalNonDiscountedPrice = Sale::where('code_document_sale', $sale->code_document_sale)
            ->where('approved', '0')
            ->sum('product_price_sale');

        // Total keseluruhan dikurangi voucher
        $newTotalPrice = $totalDiscountedPrice + $totalNonDiscountedPrice;
        $newTotalPriceAfterVoucher = $newTotalPrice - $saleDocument->voucher;

        // Update total harga di sale document
        $saleDocument->total_price_document_sale = $newTotalPriceAfterVoucher;
        $saleDocument->save();

        // Update buyer purchase amount
        $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

        // Hitung rata-rata pembelian
        $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
            ->avg('total_price_document_sale');

        // Update amount purchase buyer:
        // 1. Kurangi dulu total lama
        // 2. Tambahkan total baru
        $buyer->update([
            'amount_purchase_buyer' => number_format(
                ($buyer->amount_purchase_buyer - $oldTotalPrice) + $newTotalPriceAfterVoucher,
                2,
                '.',
                ''
            ),
            'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
        ]);

        $response = [
            'code_document_sale' => $sale->code_document_sale,
            'product_name_sale' => $sale->product_name_sale,
            'product_category_sale' => $sale->product_category_sale,
            'product_barcode_sale' => $sale->product_barcode_sale,
            'product_price_sale' => $sale->product_price_sale,
            'display_price' => $sale->display_price,
            'approved' => $sale->approved,
            'total_price_document_sale' => $saleDocument->total_price_document_sale,
            'total_display_document_sale' => $saleDocument->total_display_document_sale,
            'grand_total' => $saleDocument->grand_total
        ];

        return new ResponseResource(true, "Berhasil reject discount", $response);
    }

    public function rejectAllDiscounts($id_sale_document)
    {
        // Ambil dokumen penjualan
        $saleDocument = SaleDocument::where('id', $id_sale_document)->first();

        if (!$saleDocument) {
            return (new ResponseResource(false, "Dokumen penjualan tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        // Simpan total harga lama untuk perhitungan amount purchase buyer
        $oldTotalPrice = $saleDocument->total_price_document_sale;

        try {
            DB::beginTransaction();

            // Update semua sale yang memiliki diskon (approved 1 atau 2)
            $updatedSales = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->where(function ($query) {
                    $query->where('approved', '1');
                })
                ->get();

            // Update setiap produk yang memiliki diskon
            foreach ($updatedSales as $sale) {
                $sale->approved = '0';
                $sale->product_price_sale = $sale->display_price;
                $sale->save();
            }

            // Hitung total baru dari semua produk
            $newTotalPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->sum('product_price_sale');

            // Reset voucher jika ada
            $saleDocument->voucher = 0;

            // Update total di dokumen penjualan
            $saleDocument->total_price_document_sale = $newTotalPrice;
            $saleDocument->approved = '0';
            $saleDocument->save();

            // Update buyer purchase amount
            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            // Hitung rata-rata pembelian baru
            $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
                ->avg('total_price_document_sale');

            // Update amount purchase buyer
            $buyer->update([
                'amount_purchase_buyer' => number_format(
                    ($buyer->amount_purchase_buyer - $oldTotalPrice) + $newTotalPrice,
                    2,
                    '.',
                    ''
                ),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
            ]);

            $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
            if (!$notif) {
                return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
            }
            $notif->update(['approved' => '1']);

            DB::commit();

            // Siapkan response
            $response = [
                'code_document_sale' => $saleDocument->code_document_sale,
                'total_products_updated' => $updatedSales->count(),
                'total_price_document_sale' => $saleDocument->total_price_document_sale,
                'total_display_document_sale' => $saleDocument->total_display_document_sale,
                'grand_total' => $saleDocument->grand_total,
                'updated_products' => $updatedSales->map(function ($sale) {
                    return [
                        'product_name_sale' => $sale->product_name_sale,
                        'product_category_sale' => $sale->product_category_sale,
                        'product_barcode_sale' => $sale->product_barcode_sale,
                        'old_price' => $sale->getOriginal('product_price_sale'),
                        'new_price' => $sale->product_price_sale,
                        'display_price' => $sale->display_price
                    ];
                })
            ];

            return new ResponseResource(true, "Berhasil reject semua diskon", $response);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal reject diskon: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function doneApproveDiscount($id_sale_document)
    {
        if (empty($id_sale_document)) {
            return (new ResponseResource(false, "id tidak ada", null))->response()->setStatusCode(404);
        }

        try {
            $saleDocument = SaleDocument::where('id', $id_sale_document)
                ->update(['approved' => '0']);

            if (!$saleDocument) {
                return (new ResponseResource(false, "gagal memperbarui data", null))->response()->setStatusCode(500);
            }

            $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
            if (!$notif) {
                return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
            }
            $notif->update(['approved' => '2']);

            return new ResponseResource(true, "berhasil approve document", null);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function invoiceSale(Request $request, $id)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Ambil data SaleDocument dengan relasi sales dan users
        $saleDocument = SaleDocument::where('id', $id)
            ->with(['sales'])
            ->first();

        if (!$saleDocument) {
            return (new ResponseResource(false, "id not found", null))
                ->response()
                ->setStatusCode(404);
        }

        // Header untuk data SaleDocument
        $user = User::where('id', $saleDocument->user_id)->first();
        $headers = [
            'Username Cashier',
            'Kode Dokumen',
            'ID Pembeli',
            'Nama Pembeli',
            'Telepon Pembeli',
            'Alamat Pembeli',
            'Diskon Baru',
            'Total Produk',
            'Total Harga',
            'Harga Normal',
            'Status Penjualan',
            'Qty Kardus',
            'Harga Kardus',
            'Total Harga Kardus',
            'Tanggal Dibuat',
            'Voucher',
            'Pajak',
            'Harga Setelah Pajak'
        ];

        // Header untuk data Sales
        $salesHeaders = [
            'Nama Produk',
            'Kategori Produk',
            'Barcode Produk',
            'Harga Produk',
            'Kuantitas Produk',
            'Status Penjualan',
            'Total Diskon',
            'Tanggal Dibuat',
            'Harga Normal',
        ];

        // Buat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set header untuk SaleDocument
        $columnIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        // Isi data SaleDocument pada row kedua
        $rowIndex = 2;
        $sheet->setCellValueByColumnAndRow(1, $rowIndex, $user->username);
        $sheet->setCellValueByColumnAndRow(2, $rowIndex, $saleDocument->code_document_sale);
        $sheet->setCellValueByColumnAndRow(3, $rowIndex, $saleDocument->buyer_id_document_sale);
        $sheet->setCellValueByColumnAndRow(4, $rowIndex, $saleDocument->buyer_name_document_sale);
        $sheet->setCellValueByColumnAndRow(5, $rowIndex, $saleDocument->buyer_phone_document_sale);
        $sheet->setCellValueByColumnAndRow(6, $rowIndex, $saleDocument->buyer_address_document_sale);
        $sheet->setCellValueByColumnAndRow(7, $rowIndex, $saleDocument->new_discount_sale);
        $sheet->setCellValueByColumnAndRow(8, $rowIndex, $saleDocument->total_product_document_sale);
        $sheet->setCellValueByColumnAndRow(9, $rowIndex, $saleDocument->total_price_document_sale);
        $sheet->setCellValueByColumnAndRow(10, $rowIndex, $saleDocument->total_display_document_sale);
        $sheet->setCellValueByColumnAndRow(11, $rowIndex, $saleDocument->status_document_sale);
        $sheet->setCellValueByColumnAndRow(12, $rowIndex, $saleDocument->cardbox_qty);
        $sheet->setCellValueByColumnAndRow(13, $rowIndex, $saleDocument->cardbox_unit_price);
        $sheet->setCellValueByColumnAndRow(14, $rowIndex, $saleDocument->cardbox_total_price);
        $sheet->setCellValueByColumnAndRow(15, $rowIndex, $saleDocument->created_at);
        $sheet->setCellValueByColumnAndRow(16, $rowIndex, $saleDocument->voucher);
        $sheet->setCellValueByColumnAndRow(17, $rowIndex, $saleDocument->tax);
        $sheet->setCellValueByColumnAndRow(18, $rowIndex, $saleDocument->price_after_tax);

        // Sisipkan data Sales pada row setelahnya
        $rowIndex += 2;
        $salesColumnIndex = 1;
        foreach ($salesHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($salesColumnIndex, $rowIndex, $header);
            $salesColumnIndex++;
        }

        // Isi data Sales untuk setiap produk yang terjual
        $rowIndex++;
        foreach ($saleDocument->sales as $saleDoc) {  // Gunakan $saleDocument->sales, karena $saleDocument adalah satu objek
            $salesColumnIndex = 1;
            if ($saleDoc) {  // Pastikan saleDoc tidak null
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->product_name_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->product_category_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->product_barcode_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->product_price_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->product_qty_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->status_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->total_discount_sale);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->created_at);
                $sheet->setCellValueByColumnAndRow($salesColumnIndex++, $rowIndex, $saleDoc->display_price);
            }
            $rowIndex++;
        }

        // Tentukan nama dan path untuk file export
        $fileName = 'invoice-sale-' . $saleDocument->code_document_sale . '.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        // Buat folder jika belum ada
        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        // Simpan file ke path yang ditentukan
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        // Kembalikan response dengan URL untuk mendownload file

        $downloadUrl = url($publicPath . '/' . $fileName);
        return new ResponseResource(true, "unduh", $downloadUrl);
    }

    public function bulkingInvoiceByDateToJurnal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        $startDate = $request->input('start_date') . " 00:00:00";
        $endDate = $request->input('end_date') . " 23:59:59";

        try {
            $saleDocuments = SaleDocument::where('status_document_sale', 'selesai')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->with(['sales'])
                ->get();

            foreach ($saleDocuments as $doc) {
                foreach ($doc->sales as $sale) {
                    $cleanName = preg_replace('/[\x{1F300}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]+/u', '', $sale->product_name_sale);

                    if ($cleanName !== $sale->product_name_sale) {
                        $sale->update(['product_name_sale' => $cleanName]);
                    }
                }
            }
        } catch (\Throwable $th) {
            Log::error("Terjadi Error: " . $th->getMessage());
            return (new ResponseResource(false, "Sepertinya ada masalah!", []))
                ->response()
                ->setStatusCode(500);
        }

        $oldestSaleDocument = $saleDocuments->sortBy('created_at')->first();
        $newestSaleDocument = $saleDocuments->sortByDesc('created_at')->first();

        $data = [];

        $url = 'https://api.mekari.com/public/jurnal/api/v1/sales_invoices/batch_create';
        // $url = 'https://sandbox-api.mekari.com/public/jurnal/api/v1/sales_invoices/batch_create';

        foreach ($saleDocuments as $saleDocument) {
            $data[] = [
                "sales_invoice" => [
                    "transaction_date" => $saleDocument->created_at->format('Y-m-d'),
                    "transaction_lines_attributes" => array_map(function ($sale) {
                        return [
                            "quantity" => $sale['product_qty_sale'],
                            "rate" => $sale['product_price_sale'],
                            "discount" => $sale['new_discount_sale'],
                            // "product_code" => $sale['product_barcode_sale'],
                            "product_name" => $sale['product_category_sale'],
                            "description" => $sale['product_name_sale'],
                        ];
                    }, $saleDocument->sales->toArray()),
                    // "shipping_date" => "2016-09-13",
                    // "shipping_price" => 10000,
                    // "shipping_address" => "Ragunan",
                    // "is_shipped" => true,
                    // "ship_via" => "TIKI",
                    "reference_no" =>  $saleDocument->code_document,  // "TIKI-123456",
                    // "tracking_no" => "TN1010",
                    "address" =>  $saleDocument->buyer_address_document_sale,  // "JL. Gatot Subroto 55, Jakarta, 11739",
                    // "term_name" => "Net 60",
                    // "due_date" => "2016-10-20",
                    "discount_unit" =>  $saleDocument->new_discount_sale,  // 10,
                    // "witholding_account_name" => "Cash",
                    // "witholding_value" => 10,
                    // "witholding_type" => "percent",
                    // "warehouse_name" => "Gudang A",
                    // "warehouse_code" => "1234",
                    // "discount_type_name" => "percent",
                    "person_name" => $saleDocument->buyer_name_document_sale, // agung,
                    // "email" => "customer@example.com",
                    // "transaction_no" => "INV-10001234",
                    // "message" => "batch message 1 goes here",
                    // "memo" => "batch memo 1 goes here",
                    // "custom_id" => "invoice_asbaffatch_14",
                    // "tax_after_discount" => true
                ]
            ];
        }

        $body = [
            "sales_invoices" => $data
        ];

        // cek data yang sukses di create di jurnal
        $successfullyCreated = [];
        $failedCreated = [];

        try {
            $response = jurnalRequest('post', $url, $body);

            if (isset($response->json()['sales_invoices'])) {
                foreach ($response->json()['sales_invoices'] as $invoice) {
                    if ($invoice['sales_invoice']['status'] == 201) {
                        $successfullyCreated[] = $invoice;
                    } else {
                        $failedCreated[] = $invoice;
                    }
                }
                $message = "Bulking " . count($successfullyCreated) . " data berhasil dan " . count($failedCreated) . " data gagal!";
            } else {
                $message = "Tidak mendapatkan response dari Jurnal! tapi jangan khawatir, " . $saleDocuments->count() . " data sudah terkirim, pastikan lagi di web / apps Jurnal!";
            }

            $logFinance = LogFinance::create([
                'user_id' => auth()->id(),
                'start_date' => $oldestSaleDocument->created_at->format('d-m-Y H:i:s'),
                'end_date' => $newestSaleDocument->created_at->format('d-m-Y H:i:s'),
                'total_data' => count($data),
            ]);

            return new ResponseResource(
                true,
                $message,
                $response->json()
            );
        } catch (\Throwable $th) {
            Log::error("Terjadi Error: " . $th->getMessage());
            return (new ResponseResource(false, "Sepertinya ada masalah!", []))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function bulkingInvoiceByIdToJurnal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sale_document_ids' => 'required|array',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        $saleDocuments = SaleDocument::whereIn('buyer_name_document_sale', ["apfirdaus"])
            // ->whereHas('sales')
            // ->whereNotIn(DB::raw('DATE(created_at)'), ["2024-08-20", "2024-08-19", "2024-08-13", "2024-07-31", "2024-07-29", "2024-09-03", "2024-08-06", "2024-07-20", "2024-09-20"])
            ->whereIn(DB::raw('DATE(created_at)'), ["2024-05-29"])
            ->with(['sales'])
            ->get();

        // return $saleDocuments;

        // Filter nama produk yang mengandung emoji
        foreach ($saleDocuments as $doc) {
            foreach ($doc->sales as $sale) {
                $cleanName = preg_replace('/[\x{1F300}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]+/u', '', $sale->product_name_sale);

                if ($cleanName !== $sale->product_name_sale) {
                    $sale->update(['product_name_sale' => $cleanName]);
                }
            }
        }

        try {
            // $saleDocuments = SaleDocument::where('status_document_sale', 'selesai')
            //     ->whereIn('id', $request->input('sale_document_ids'))
            //     ->with(['sales'])
            //     ->get();
        } catch (\Throwable $th) {
            Log::error("Terjadi Error: " . $th->getMessage());
            return (new ResponseResource(false, "Sepertinya ada masalah!", []))
                ->response()
                ->setStatusCode(500);
        }

        $data = [];

        // $url = 'https://api.mekari.com/public/jurnal/api/v1/sales_invoices/batch_create';
        $url = 'https://sandbox-api.mekari.com/public/jurnal/api/v1/sales_invoices/batch_create';

        foreach ($saleDocuments as $saleDocument) {
            $data[] = [
                "sales_invoice" => [
                    "transaction_date" => $saleDocument->created_at->format('Y-m-d'),
                    "transaction_lines_attributes" => array_map(function ($sale) {
                        return [
                            "quantity" => $sale['product_qty_sale'],
                            "rate" => $sale['product_price_sale'],
                            "discount" => $sale['new_discount_sale'],
                            // "product_code" => $sale['product_barcode_sale'],
                            "product_name" => $sale['product_category_sale'],
                            "description" => $sale['product_name_sale'],
                        ];
                    }, $saleDocument->sales->toArray()),
                    // "shipping_date" => "2016-09-13",
                    // "shipping_price" => 10000,
                    // "shipping_address" => "Ragunan",
                    // "is_shipped" => true,
                    // "ship_via" => "TIKI",
                    "reference_no" =>  $saleDocument->code_document,  // "TIKI-123456",
                    // "tracking_no" => "TN1010",
                    "address" =>  $saleDocument->buyer_address_document_sale,  // "JL. Gatot Subroto 55, Jakarta, 11739",
                    // "term_name" => "Net 60",
                    // "due_date" => "2016-10-20",
                    "discount_unit" =>  $saleDocument->new_discount_sale,  // 10,
                    // "witholding_account_name" => "Cash",
                    // "witholding_value" => 10,
                    // "witholding_type" => "percent",
                    // "warehouse_name" => "Gudang A",
                    // "warehouse_code" => "1234",
                    // "discount_type_name" => "percent",
                    "person_name" => $saleDocument->buyer_name_document_sale, // agung,
                    // "email" => "customer@example.com",
                    // "transaction_no" => "INV-10001234",
                    // "message" => "batch message 1 goes here",
                    // "memo" => "batch memo 1 goes here",
                    // "custom_id" => "invoice_asbaffatch_14",
                    // "tax_after_discount" => true
                ]
            ];
        }

        return $data;

        $body = [
            "sales_invoices" => $data
        ];

        // cek data yang sukses di create di jurnal
        $successfullyCreated = [];
        $failedCreated = [];

        try {
            $response = jurnalRequest('post', $url, $body);

            if (isset($response->json()['sales_invoices'])) {
                foreach ($response->json()['sales_invoices'] as $invoice) {
                    if ($invoice['sales_invoice']['status'] == 201) {
                        $successfullyCreated[] = $invoice;
                    } else {
                        $failedCreated[] = $invoice;
                    }
                }
                $message = "Bulking " . count($successfullyCreated) . " data berhasil dan " . count($failedCreated) . " data gagal!";
            } else {
                $message = "Tidak mendapatkan response dari Jurnal! tapi jangan khawatir, " . $saleDocuments->count() . " data sudah terkirim, pastikan lagi di web / apps Jurnal!";
            }

            return new ResponseResource(
                true,
                $message,
                $response->json()
            );
        } catch (\Throwable $th) {
            Log::error("Terjadi Error: " . $th->getMessage());
            return (new ResponseResource(false, "Sepertinya ada masalah!", []))
                ->response()
                ->setStatusCode(500);
        }
    }
}
