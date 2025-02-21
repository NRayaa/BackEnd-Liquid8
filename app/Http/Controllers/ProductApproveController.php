<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductapproveResource;
use App\Http\Resources\ResponseResource;
use App\Http\Resources\DuplicateRequestResource;
use App\Jobs\ProductBatch;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\ProductApprove;
use App\Models\Product_old;
use App\Models\RiwayatCheck;
use App\Models\StagingProduct;
use App\Models\User;
use App\Models\UserScanWeb;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;


class ProductApproveController extends Controller
{
    // Array bulan dalam bahasa Indonesia

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $newProducts = ProductApprove::latest()->where(function ($queryBuilder) use ($query) {
            $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
        })->whereNotIn('new_status_product', ['dump', 'expired', 'sale', 'migrate', 'repair'])->paginate(100);

        return new ResponseResource(true, "list new product", $newProducts);
    }

    public function byDocument(Request $request)
    {
        $query = $request->input('code_document');

        $newProducts = ProductApprove::where('code_document', $query)->paginate(100);

        if ($newProducts->isEmpty()) {
            return new ResponseResource(false, "No data found", null);
        }

        return new ResponseResource(true, "List new products", $newProducts);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        $userId = auth()->id();

        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'old_barcode_product' => 'required|exists:product_olds,old_barcode_product',
            // 'new_barcode_product' => 'unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            // 'new_date_in_product' => 'required|date',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',

        ], [
            'old_barcode_product.exists' => 'barcode tidak ada',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }


        $oldBarcode = $request->input('old_barcode_product');
        $ttlRedis = 5;
        $throttleTtl = 7;
        $redisKey = "barcode:$oldBarcode";
        $rateLimiter = app(\Illuminate\Cache\RateLimiter::class);
        $throttleKey = "throttle:$oldBarcode";
        if ($rateLimiter->tooManyAttempts($throttleKey, 1)) {
            return new DuplicateRequestResource(
                false,
                "throttle - barcode awal di scan lebih dari 1x dalam waktu $throttleTtl detik",
                $oldBarcode,
                429
            );
        }
        // Tambahkan hit untuk throttle
        $rateLimiter->hit($throttleKey, $throttleTtl);
        // Lua Script untuk Atomic Lock
        $luaScript = '
           if redis.call("exists", KEYS[1]) == 1 then
               return 0 -- Duplikasi
           else
               redis.call("setex", KEYS[1], ARGV[1], "processing")
               return 1 -- Sukses
           end
       ';

        $redis = app('redis');
        $lockAcquired = $redis->eval($luaScript, 1, $redisKey, $ttlRedis);
        if ($lockAcquired == 0) {
            return new DuplicateRequestResource(
                false,
                "redis - barcode awal di scan lebih dari 1x dalam waktu $ttlRedis detik",
                $oldBarcode,
                429
            );
        }

       
        $status = $request->input('condition');
        $description = $request->input('deskripsi', '');

        $qualityData = $this->prepareQualityData($status, $description);

        $inputData = $this->prepareInputData($request, $status, $qualityData, $userId);

        $oldBarcode = $request->input('old_barcode_product');


        DB::beginTransaction();
        try {

            $document = Document::where('code_document', $request->input('code_document'))->first();

            if ($document->custom_barcode) {
                $generate = newBarcodeCustom($document->custom_barcode, $userId);
            } else {
                $generate = generateNewBarcode($inputData['new_category_product']);
            }


            $inputData['new_barcode_product'] = $generate;

            $tables = [
                New_product::class,
                ProductApprove::class,
                StagingProduct::class,
            ];

            $oldBarcodeExists = false;
            $newBarcodeExists = false;

            foreach ($tables as $table) {
                if ($table::where('old_barcode_product', $oldBarcode)->exists()) {
                    $oldBarcodeExists = true;
                }
                if ($table::where('new_barcode_product', $inputData['new_barcode_product'])->exists()) {
                    $newBarcodeExists = true;
                }
            }

            if ($oldBarcodeExists) {
                return new ProductapproveResource(false, false, "The old barcode already exists", $inputData);
            }

            if ($newBarcodeExists) {
                return new ResponseResource(false, "The new barcode already exists", $inputData);
            }

            $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
            $totalDataIn = 1 + $riwayatCheck->total_data_in;

            if ($qualityData['lolos'] != null) {
                $modelClass = ProductApprove::class;
                $riwayatCheck->total_data_lolos += 1;
            } else if ($qualityData['damaged'] != null) {
                $modelClass = New_product::class;
                $riwayatCheck->total_data_damaged += 1;
            } else if ($qualityData['abnormal'] != null) {
                $modelClass = New_product::class;
                $riwayatCheck->total_data_abnormal += 1;
            }

            $redisKey = 'product_batch';
            $batchSize = 4;

            if (isset($modelClass)) {
                Redis::rpush($redisKey, json_encode($inputData));

                $listSize = Redis::llen($redisKey);

                if ($listSize >= $batchSize) {
                    ProductBatch::dispatch($batchSize);
                }
            }
            $this->deleteOldProduct($inputData['code_document'], $request->input('old_barcode_product'));

            UserScanWeb::updateOrCreateDailyScan($userId, $document->id);


            $totalDiscrepancy = Product_old::where('code_document', $request->input('code_document'))->pluck('code_document');

            $riwayatCheck->update([
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $riwayatCheck->total_data_lolos,
                'total_data_damaged' => $riwayatCheck->total_data_damaged,
                'total_data_abnormal' => $riwayatCheck->total_data_abnormal,
                'total_discrepancy' => count($totalDiscrepancy),
                'status_approve' => 'pending',
                // persentase
                'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalDataIn / $document->total_column_in_document) * 100,
                'percentage_lolos' => ($riwayatCheck->total_data_lolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($riwayatCheck->total_data_damaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($riwayatCheck->total_data_abnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($totalDiscrepancy) / $document->total_column_in_document) * 100,
            ]);
            //end data history


            $this->updateDocumentStatus($request->input('code_document'));

            DB::commit();

            return new ProductapproveResource(true, true, "New Produk Berhasil ditambah", $inputData);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function prepareQualityData($status, $description)
    {
        return [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null,
        ];
    }

    private function prepareInputData($request, $status, $qualityData, $userId)
    {
        $inputData = $request->only([
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_status_product',
            'new_category_product',
            'new_tag_product',
            'condition',
            'deskripsi',
            'type',
            'user_id'
        ]);

        if ($inputData['old_price_product'] < 100000) {
            $inputData['new_barcode_product'] = $inputData['old_barcode_product'];
        }

        $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
        $inputData['new_quality'] = json_encode($qualityData);
        $inputData['type'] = 'type1';

        $inputData['new_discount'] = 0;
        $inputData['user_id'] = $userId;
        $inputData['display_price'] = $inputData['new_price_product'];

        if ($status !== 'lolos') {
            $inputData['new_category_product'] = null;
            $inputData['new_price_product'] = null;
        }

        if ($inputData['new_price_product'] == null) {
            $inputData['display_price'] = 0;
        }

        return $inputData;
    }

    private function deleteOldProduct($code_document, $old_barcode_product)
    {
        return DB::statement(
            "DELETE FROM product_olds WHERE code_document = ? AND old_barcode_product = ? LIMIT 1",
            [$code_document, $old_barcode_product]
        );
    }

    private function updateDocumentStatus($codeDocument)
    {
        $document = Document::where('code_document', $codeDocument)->firstOrFail();
        if ($document->status_document === 'pending') {
            $document->update(['status_document' => 'in progress']);
        }
    }

    public function addProductOld(Request $request)
    {
        $userId = auth()->id();
        try {

            DB::beginTransaction();
            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');

            $qualityData = $this->prepareQualityData($status, $description);

            $inputData = $this->prepareInputData($request, $status, $qualityData, $userId);

            $document = Document::where('code_document', $inputData['code_document'])->first();
            $generate = null;

            $maxRetry = 5;
            for ($i = 0; $i < $maxRetry; $i++) {
                if ($document->custom_barcode) {
                    $generate = newBarcodeCustom($document->custom_barcode, $userId);
                } else {
                    $generate = generateNewBarcode($inputData['new_category_product']);
                }

                if (!ProductApprove::where('new_barcode_product', $generate)->exists()) {
                    break;
                }

                if ($i === $maxRetry - 1) {
                    throw new \Exception("Failed to generate unique barcode after multiple attempts.");
                }
            }

            $inputData['new_barcode_product'] = $generate;

            // Set display price
            $inputData['display_price'] = $inputData['new_price_product'] ?? $inputData['old_price_product'];

            $this->deleteOldProduct($inputData['code_document'], $inputData['old_barcode_product']);

            $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
            $totalDataIn = 1 + $riwayatCheck->total_data_in;

            if ($qualityData['lolos'] != null) {
                $riwayatCheck->total_data_lolos += 1;
            } else if ($qualityData['damaged'] != null) {
                $riwayatCheck->total_data_damaged += 1;
            } else if ($qualityData['abnormal'] != null) {
                $riwayatCheck->total_data_abnormal += 1;
            }

            UserScanWeb::updateOrCreateDailyScan($userId, $document->id);


            $totalDiscrepancy = Product_old::where('code_document', $request->input('code_document'))->pluck('code_document');

            $riwayatCheck->update([
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $riwayatCheck->total_data_lolos,
                'total_data_damaged' => $riwayatCheck->total_data_damaged,
                'total_data_abnormal' => $riwayatCheck->total_data_abnormal,
                'total_discrepancy' => count($totalDiscrepancy),
                'status_approve' => 'pending',
                // persentase
                'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalDataIn / $document->total_column_in_document) * 100,
                'percentage_lolos' => ($riwayatCheck->total_data_lolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($riwayatCheck->total_data_damaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($riwayatCheck->total_data_abnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($totalDiscrepancy) / $document->total_column_in_document) * 100,
            ]);

            $this->updateDocumentStatus($inputData['code_document']);

            $newProduct = ProductApprove::create($inputData);

            DB::commit();
            return new ProductapproveResource(true, true, "New Produk Berhasil ditambah", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductApprove $productApprove)
    {
        return new ResponseResource(true, "data new product", $productApprove);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductApprove $productApprove)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductApprove $productApprove)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'old_barcode_product' => 'required',
            'new_barcode_product' => 'required',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',
            'new_discount',
            'display_price',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = $request->input('condition');
        $description = $request->input('deskripsi', '');

        $qualityData = [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null,
        ];

        $inputData = $request->only([
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_category_product',
            'new_tag_product',
            'new_discount',
            'display_price',
        ]);

        $indonesiaTime = Carbon::now('Asia/Jakarta');
        $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

        if ($status !== 'lolos') {
            // Set nilai-nilai default jika status bukan 'lolos'
            $inputData['new_price_product'] = null;
            $inputData['new_category_product'] = null;
        }

        $inputData['new_quality'] = json_encode($qualityData);

        $productApprove->update($inputData);

        return new ResponseResource(true, "New Produk Berhasil di Update", $productApprove);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductApprove $productApprove)
    {
        // Salin data dari ProductApprove ke New_product
        $newProduct = new Product_old([
            'code_document' => $productApprove->code_document,
            'old_barcode_product' => $productApprove->old_barcode_product,
            'old_name_product' => $productApprove->new_name_product,
            'old_quantity_product' => $productApprove->new_quantity_product,
            'old_price_product' => $productApprove->old_price_product,

            // Tambahkan kolom lainnya sesuai kebutuhan
        ]);

        $newProduct->save(); // Simpan data baru ke New_product

        // Hapus data dari ProductApprove setelah data baru tersimpan
        $productApprove->delete();

        return new ResponseResource(true, "Data berhasil dihapus dan di kembalikan ke list product scan", $newProduct);
    }

    public function deleteAll()
    {
        try {
            // ListProductBP::query()->delete();
            ProductApprove::query()->delete();
            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return response()->json(["error" => $e], 402);
        }
    }

    public function getTagColor(Request $request)
    {
        $query = $request->input('q');
        try {
            $productByTagColor = ProductApprove::latest()
                ->whereNotNull('new_tag_product')
                ->when($query, function ($queryBuilder) use ($query) {
                    $queryBuilder->where('new_tag_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                })
                ->paginate(50);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(500);
        }

        return new ResponseResource(true, "list product by tag color", $productByTagColor);
    }

    public function getByCategory(Request $request)
    {
        $query = $request->input('q');
        try {
            $productByTagColor = ProductApprove::latest()
                ->whereNotNull('new_category_product')
                ->when($query, function ($queryBuilder) use ($query) {
                    $queryBuilder->where('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                })
                ->paginate(50);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(500);
        }

        return new ResponseResource(true, "list product by tag color", $productByTagColor);
    }

    public function searchByDocument(Request $request)
    {
        $code_documents = ProductApprove::where('code_document', $request->input('search'))->paginate(50);

        if ($code_documents->isNotEmpty()) {
            return new ResponseResource(true, "list product_old", $code_documents);
        } else {
            return new ResponseResource(false, "code document tidak ada", null);
        }
    }

    public function documentsApprove(Request $request)
    {
        $query = $request->input('q');

        $notifQuery = Notification::with('riwayat_check')->latest();

        if (!empty($query)) {
            $notifQuery->whereHas('riwayat_check', function ($q) use ($query) {
                $q->where('status_approve', $query);
            });
        } else {
            $notifQuery->whereHas('riwayat_check', function ($q) {
                $q->where('status_approve', 'pending')->orWhere('status_approve', 'done');
            });
        }

        $documents = $notifQuery->paginate(20);

        return new ResponseResource(true, "Document Approves", $documents);
    }

    public function productsApproveByDoc(Request $request, $code_document)
    {
        $query = $request->input('q');
        $user = User::with('role')->find(auth()->id());

        if ($user) {
            // Memulai query builder untuk ProductApprove
            $productsQuery = ProductApprove::where('code_document', $code_document);

            // Menambahkan kondisi pencarian jika ada query
            $productsQuery->when($query, function ($queryBuilder) use ($query) {
                $queryBuilder->where(function ($subQuery) use ($query) {
                    $subQuery->whereNotNull('new_category_product')
                        ->where('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_status_product', 'LIKE', '%' . $query . '%');
                });
            });

            $products = $productsQuery->paginate(50);

            return new ResponseResource(true, 'products', $products);
        } else {
            return (new ResponseResource(false, "User tidak dikenali", null))->response()->setStatusCode(404);
        }
    }

    public function delete_all_by_codeDocument(Request $request)
    {
        $code_document = $request->input('code_document');
        DB::beginTransaction();

        try {
            $products = ProductApprove::where('code_document', $code_document)->get();

            foreach ($products as $product) {
                $newProduct = new Product_old([
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'old_name_product' => $product->new_name_product,
                    'old_quantity_product' => $product->new_quantity_product,
                    'old_price_product' => $product->old_price_product,
                ]);
                $newProduct->save();
            }

            ProductApprove::where('code_document', $code_document)->delete();

            $document = Document::where('code_document', $code_document)->first();
            $document->update(['status_document' => 'pending']);

            DB::commit();
            return new ResponseResource(true, "berhasil dihapus", $products);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "transaksi salah: ", $e->getMessage());
        }
    }
    // public function processRemainingBatch()
    // {
    //     $batchSize = 5;
    //     $redisKey = 'product_batch';

    //     $currentBatchCount = Redis::llen($redisKey);

    //     if ($currentBatchCount > 0 && $currentBatchCount < $batchSize) {
    //         \Log::info("Processing remaining batch data with size: $currentBatchCount");

    //         ProcessProductData::dispatch($currentBatchCount, \App\Models\ProductApprove::class);
    //     }
    // }

}