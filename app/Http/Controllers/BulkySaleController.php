<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\Bundle;
use App\Models\BulkySale;
use App\Models\BagProducts;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\BulkyDocument;
use App\Models\StagingProduct;
use Illuminate\Validation\Rule;
use App\Imports\BulkySaleImport;
use App\Imports\BulkySaleImport2;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\ResponseResource;
use App\Models\Bkl;
use App\Models\BklProduct;
use App\Models\Sale;
use Illuminate\Support\Facades\Validator;

class BulkySaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId = auth()->id();

        $bulkyDocument = BulkyDocument::with('bulkySales')
            ->where('status_bulky', 'proses')
            ->where('user_id', $userId)
            ->first();

        $resource = new ResponseResource(true, "list data bulky sale", $bulkyDocument);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')->where('user_id', $user->id)->first();

        $validator = Validator::make($request->all(), [
            'file_import' => 'nullable|file|mimes:xlsx,xls,csv|max:1536',
            'barcode_product' => 'nullable|required_without:file_import',
            'buyer_id' => [
                Rule::requiredIf(is_null($bulkyDocument)),
                'exists:buyers,id',
            ],
            'discount_bulky' => [
                Rule::requiredIf(is_null($bulkyDocument)),
                'numeric',
                'min:0',
                'max:100',
            ],
            'category_bulky' => [
                Rule::requiredIf(is_null($bulkyDocument)),
                'string',
                'min:3'
            ],
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        $buyer = Buyer::findOrFail($request->buyer_id);

        if (!$bulkyDocument) {
            $bulkyDocument =  BulkyDocument::create([
                'user_id' => $user->id,
                'name_user' => $user->name,
                'total_product_bulky' => 0,
                'total_old_price_bulky' => 0,
                'buyer_id' => $buyer->id,
                'name_buyer' => $buyer->name_buyer,
                'discount_bulky' => $request->discount_bulky,
                'after_price_bulky' => 0,
                'category_bulky' => $request->category_bulky,
                'status_bulky' => 'proses',
            ]);
        }

        if ($request->hasFile('file_import')) {
            $import = new BulkySaleImport($bulkyDocument->id, $bulkyDocument->discount_bulky, null);

            Excel::import($import, $request->file('file_import'));

            if ($bulkyDocument->bulkySales->isEmpty()) {
                $bulkyDocument->delete();

                $resource = new ResponseResource(false, "Tidak ada data yang valid karena semua barcode tidak ditemukan.", [
                    "total_barcode_found" => $import->getTotalFoundBarcode(),
                    "total_barcode_not_found" => $import->getTotalNotFoundBarcode(),
                    "data_barcode_not_found" => $import->getDataNotFoundBarcode(),
                ]);
                return $resource->response()->setStatusCode(404);
            }

            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", [
                "import" => true,
                "total_barcode_found" => $import->getTotalFoundBarcode(),
                "total_barcode_not_found" => $import->getTotalNotFoundBarcode(),
                "data_barcode_not_found" => $import->getDataNotFoundBarcode(),
                "data_barcode_duplicate" => $import->getDataDuplicateBarcode(),
                // "bulky_documents" => $bulkyDocument->load('bulkySales'),
            ]);
        } else {
            // lock barcode ini agar tidak bisa diinputkan secara bersamaan
            $lockKey = "barcode:{$request->barcode_product}";
            $lock = cache()->lock($lockKey, 5);
            if (!$lock->get()) {
                return (new ResponseResource(false, "Data sedang diproses!", []))->response()->setStatusCode(422);
            }

            DB::beginTransaction();
            try {
                $productBulkySale = BulkySale::where('barcode_bulky_sale', $request->input('barcode_product'))
                    ->lockForUpdate()
                    ->first();

                if ($productBulkySale) {
                    $resource = new ResponseResource(false, "Data sudah dimasukkan!", $productBulkySale);
                    return $resource->response()->setStatusCode(422);
                }

                $models = [
                    'new_product' => New_product::where('new_barcode_product', $request->barcode_product)->first(),
                    'staging_product' => StagingProduct::where('new_barcode_product', $request->barcode_product)->first(),
                    'bundle_product' => Bundle::where('barcode_bundle', $request->barcode_product)->first(),
                ];

                $product = null;

                foreach ($models as $type => $model) {
                    if (!$model) continue;

                    $status = match ($type) {
                        'new_product', 'staging_product' => $model->new_status_product,
                        'bundle_product' => $model->product_status,
                    };

                    if ($status === 'sale') {
                        return (new ResponseResource(false, "Barcode sudah pernah diinputkan!", []))
                            ->response()
                            ->setStatusCode(422);
                    }

                    $product = match ($type) {
                        'new_product', 'staging_product' => [
                            'barcode' => $model->new_barcode_product,
                            'category' => $model->new_category_product,
                            'name' => $model->new_name_product,
                            'old_price' => $model->old_price_product,
                            'status' => $model->new_status_product,
                            'actual_created_at' => $model->created_at,
                            'actual_old_price_product' => $model->actual_old_price_product,
                        ],
                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'old_price' => $model->total_price_bundle,
                            'status' => $model->product_status,
                            'actual_created_at' => $model->created_at ?? null,
                            'actual_old_price_product' => $model->product_bundles->sum('actual_old_price_product') ?? 0,
                        ],
                    };

                    match ($type) {
                        'new_product', 'staging_product' => $model->update([
                            'new_status_product' => 'sale',
                            'date_out' => now(),
                            'type_out' => 'cargo'
                        ]),
                        'bundle_product' => $model->update(['product_status' => 'sale']),
                    };

                    break;
                }

                if (!$product) {
                    return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
                }

                $afterPriceBulkySale = $product['old_price'] - ($product['old_price'] * $bulkyDocument->discount_bulky / 100);
                $bulkySale = BulkySale::create([
                    'bulky_document_id' => $bulkyDocument->id,
                    'barcode_bulky_sale' => $request->input('barcode_product'),
                    'product_category_bulky_sale' => $product['category'] ?? null,
                    'name_product_bulky_sale' => $product['name'] ?? null,
                    'old_price_bulky_sale' => $product['old_price'] ?? null,
                    'status_product_before' => $product['status'],
                    'after_price_bulky_sale' => $afterPriceBulkySale,
                    'actual_old_price_product' => $product['actual_old_price_product'] ?? null,
                ]);

                $resource = new ResponseResource(true, "Data berhasil di simpan!", $bulkySale);
                $lock->release();
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $lock->release();
                Log::error('Error storing bulky sale: ' . $e->getMessage());
                $resource = (new ResponseResource(false, "Data gagal di simpan!", []))->response()->setStatusCode(500);
            }
        }

        DB::beginTransaction();
        try {
            $bulkyDocument->update([
                'total_product_bulky' => $bulkyDocument->bulkySales->count(),
                'total_old_price_bulky' => $bulkyDocument->bulkySales->sum('old_price_bulky_sale'),
                'after_price_bulky' => $bulkyDocument->bulkySales->sum('after_price_bulky_sale'),
            ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating bulky document: ' . $e->getMessage());
            return (new ResponseResource(false, "Data gagal di simpan!", []))->response()->setStatusCode(500);
        }

        return $resource;
    }

    /**
     * Display the specified resource.
     */
    public function show(BulkySale $bulkySale)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BulkySale $bulkySale)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BulkySale $bulkySale)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function store2(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $user = auth()->user();
        $bulkyDocument = BulkyDocument::find($request->bulky_document_id);

        if (!$bulkyDocument) {
            return (new ResponseResource(false, "Data bulky tidak ditemukan!", null))->response()->setStatusCode(404);
        }
        if ($bulkyDocument->status_bulky !== 'proses') {
            return (new ResponseResource(false, "Data bulky sudah selesai!", null))->response()->setStatusCode(400);
        }

        $validator = Validator::make($request->all(), [
            'file_import' => 'nullable|file|mimes:xlsx,xls,csv|max:1536',
            'barcode_product' => 'nullable|required_without:file_import',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        $bagProduct = BagProducts::latest()
            ->where('user_id', $user->id)
            ->where('bulky_document_id', $bulkyDocument->id)
            ->where('status', 'process')
            ->first();

        if (!$bagProduct) {
            return (new ResponseResource(false, "Karung produk tidak ditemukan!", []))->response()->setStatusCode(404);
        }

        if ($request->hasFile('file_import')) {
            DB::beginTransaction();
            try {
                $import = new BulkySaleImport2($bulkyDocument->id, $bulkyDocument->discount_bulky, $bagProduct->id);
                Excel::import($import, $request->file('file_import'));

                if ($bulkyDocument->bulkySales()->count() === 0) {
                    $bulkyDocument->delete();
                    DB::rollBack();
                    return (new ResponseResource(false, "Tidak ada data yang valid karena semua barcode tidak ditemukan.", [
                        "total_barcode_found" => $import->getTotalFoundBarcode(),
                        "total_barcode_not_found" => $import->getTotalNotFoundBarcode(),
                        "data_barcode_not_found" => $import->getDataNotFoundBarcode(),
                    ]))->response()->setStatusCode(404);
                }

                $bagProduct->update(['total_product' => $bagProduct->bulkySales()->count()]);
                $bulkyDocument->update([
                    'total_product_bulky' => $bulkyDocument->bulkySales()->count(),
                    'total_old_price_bulky' => $bulkyDocument->bulkySales()->sum('old_price_bulky_sale'),
                    'after_price_bulky' => $bulkyDocument->bulkySales()->sum('after_price_bulky_sale'),
                ]);

                DB::commit();
                return (new ResponseResource(true, "Data berhasil ditambahkan!", [
                    "import" => true,
                    "total_barcode_found" => $import->getTotalFoundBarcode(),
                    "total_barcode_not_found" => $import->getTotalNotFoundBarcode(),
                    "data_barcode_not_found" => $import->getDataNotFoundBarcode(),
                    "data_barcode_duplicate" => $import->getDataDuplicateBarcode(),
                ]))->response();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error import bulky sale: ' . $e->getMessage());
                return (new ResponseResource(false, "Gagal memproses import Excel!", []))->response()->setStatusCode(500);
            }
        }

        $lockKey = "barcode:{$request->barcode_product}";
        $lock = cache()->lock($lockKey, 5);

        if (!$lock->get()) {
            return (new ResponseResource(false, "Data sedang diproses!", []))->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $productBulkySale = BulkySale::where('barcode_bulky_sale', $request->input('barcode_product'))
                ->lockForUpdate()->first();

            if ($productBulkySale) {
                $lock->release();
                DB::rollBack();
                return (new ResponseResource(false, "Data sudah dimasukkan!", $productBulkySale))->response()->setStatusCode(422);
            }

            $models = [
                'new_product' => New_product::where('new_barcode_product', $request->barcode_product)->first(),
                'staging_product' => StagingProduct::where('new_barcode_product', $request->barcode_product)->first(),
                'bundle_product' => Bundle::where('barcode_bundle', $request->barcode_product)->first(),
                'bkl_product' => BklProduct::where('new_barcode_product', $request->barcode_product)->first(),
            ];

            $product = null;
            $foundType = null;
            $foundModel = null;

            foreach ($models as $type => $model) {
                if (!$model) continue;

                $status = match ($type) {
                    'new_product', 'staging_product', 'bkl_product' => $model->new_status_product,
                    'bundle_product' => $model->product_status,
                };

                if ($status === 'sale') {
                    $lock->release();
                    DB::rollBack();
                    return (new ResponseResource(false, "Barcode sudah pernah diinputkan (Terjual)!", []))->response()->setStatusCode(422);
                }

                $product = match ($type) {
                    'new_product', 'staging_product', 'bkl_product' => [
                        'barcode' => $model->new_barcode_product,
                        'category' => $model->new_category_product,
                        'tag' => $model->new_tag_product,
                        'name' => $model->new_name_product,
                        'old_price' => $model->old_price_product,
                        'status' => $model->new_status_product,
                        'qty' => $model->new_quantity_product ?? null,
                        'code_document' => $model->code_document ?? null,
                        'old_barcode_product' => $model->old_barcode_product ?? null,
                        'new_date_in_product' => $model->new_date_in_product ?? null,
                        'display_price' => $model->display_price ?? null,
                        'created_at' => $model->created_at,
                        'actual_old_price_product' => $model->actual_old_price_product ?? $model->old_price_product,
                    ],
                    'bundle_product' => [
                        'barcode' => $model->barcode_bundle,
                        'category' => $model->category,
                        'tag' => $model->name_color,
                        'name' => $model->name_bundle,
                        'old_price' => $model->total_price_bundle,
                        'status' => $model->product_status,
                        'qty' => $model->total_product_bundle ?? null,
                        'code_document' => $model->product_bundles->first()?->code_document ?? null,
                        'old_barcode_product' => $model->product_bundles->first()?->old_barcode_product ?? null,
                        'new_date_in_product' => $model->product_bundles->first()?->date_in_product ?? null,
                        'display_price' => $model->product_bundles->first()?->display_price ?? null,
                        'created_at' => $model->created_at ?? null,
                        'actual_old_price_product' => $model->product_bundles->sum('actual_old_price_product') ?? 0,
                    ],
                };
                $foundType = $type;
                $foundModel = $model;
                break;
            }

            if (!$product) {
                $lock->release();
                DB::rollBack();
                return (new ResponseResource(false, "Barcode tidak ditemukan di sistem!", []))->response()->setStatusCode(404);
            }

            $bagType = $bagProduct->type ?? 'category';
            $bagValue = strtolower(trim($bagProduct->category_bag));

            if ($bagType === 'color') {
                $productColor = strtolower(trim($product['tag'] ?? ''));
                if ($productColor !== $bagValue) {
                    $lock->release();
                    DB::rollBack();
                    return (new ResponseResource(false, "Warna produk ({$productColor}) tidak sesuai dengan karung khusus warna ({$bagValue})!", []))->response()->setStatusCode(422);
                }
            } else {
                $productCat = strtolower(trim($product['category'] ?? ''));
                $cleanProductCat = trim(preg_replace('/[^a-z]+/i', ' ', $productCat));
                $cleanBagValue = trim(preg_replace('/[^a-z]+/i', ' ', $bagValue));

                $productWords = array_filter(explode(' ', $cleanProductCat));
                $bagWords = array_filter(explode(' ', $cleanBagValue));
                $commonWords = array_intersect($productWords, $bagWords);

                if (empty($commonWords)) {
                    $lock->release();
                    DB::rollBack();
                    return (new ResponseResource(false, "Kategori produk ({$product['category']}) tidak cocok dengan karung kategori ({$bagProduct->category_bag})!", []))->response()->setStatusCode(422);
                }
            }

            match ($foundType) {
                'new_product', 'staging_product', 'bkl_product' => $foundModel->update([
                    'new_status_product' => 'sale',
                    'date_out' => now(),
                    'type_out' => 'cargo'
                ]),
                'bundle_product' => $foundModel->update(['product_status' => 'sale']),
            };

            $afterPriceBulkySale = $product['old_price'] - ($product['old_price'] * $bulkyDocument->discount_bulky / 100);
            $bulkySale = BulkySale::create([
                'bulky_document_id' => $bulkyDocument->id ?? null,
                'barcode_bulky_sale' => $request->input('barcode_product'),
                'product_category_bulky_sale' => $product['category'] ?? $product['tag'] ?? null,
                'name_product_bulky_sale' => $product['name'] ?? null,
                'old_price_bulky_sale' => $product['old_price'] ?? null,
                'status_product_before' => $product['status'],
                'after_price_bulky_sale' => $afterPriceBulkySale,
                'bag_product_id' => $bagProduct->id ?? null,
                'qty' => $product['qty'] ?? null,
                'code_document' => $product['code_document'] ?? null,
                'old_barcode_product' => $product['old_barcode_product'] ?? null,
                'new_date_in_product' => $product['new_date_in_product'] ?? null,
                'display_price' => $product['display_price'] ?? 0,
                'actual_created_at' => $product['created_at'] ?? null,
                'actual_old_price_product' => $product['actual_old_price_product'] ?? null,
            ]);

            $bagProduct->update([
                'total_product' => $bagProduct->bulkySales()->count(),
            ]);

            $bulkyDocument->update([
                'total_product_bulky' => $bulkyDocument->bulkySales()->count(),
                'total_old_price_bulky' => $bulkyDocument->bulkySales()->sum('old_price_bulky_sale'),
                'after_price_bulky' => $bulkyDocument->bulkySales()->sum('after_price_bulky_sale'),
            ]);

            DB::commit();
            $lock->release();
            return (new ResponseResource(true, "Data berhasil di simpan!", $bulkySale))->response();
        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();
            Log::error('Error storing bulky sale: ' . $e->getMessage());
            return (new ResponseResource(false, "Data gagal di simpan!", []))->response()->setStatusCode(500);
        }
    }

    public function destroy(BulkySale $bulkySale)
    {
        DB::beginTransaction();
        try {
            $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')
                ->where('id', $bulkySale->bulky_document_id)
                ->first();

            if (!$bulkyDocument) {
                DB::rollBack();
                return (new ResponseResource(false, "Dokumen sudah selesai / tidak ditemukan, data tidak bisa dihapus!", []))->response()->setStatusCode(404);
            }

            $bagProduct = BagProducts::where('id', $bulkySale->bag_product_id)->first();

            $models = [
                'new_product' => New_product::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                'staging_product' => StagingProduct::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                'bundle_product' => Bundle::where('barcode_bundle', $bulkySale->barcode_bulky_sale)->first(),
                'bkl_product' => BklProduct::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
            ];

            foreach ($models as $type => $model) {
                if ($model) {
                    match ($type) {
                        'new_product', 'staging_product', 'bkl_product' => $model->update([
                            'new_status_product' => $bulkySale->status_product_before,
                            'date_out' => null,
                            'type_out' => null
                        ]),
                        'bundle_product' => $model->update(['product_status' => $bulkySale->status_product_before]),
                    };
                    break;
                }
            }

            $bulkySale->delete();

            if ($bagProduct) {
                $bagProduct->update([
                    'total_product' => $bagProduct->bulkySales()->count()
                ]);
            }

            $bulkyDocument->update([
                'total_product_bulky' => $bulkyDocument->bulkySales()->count(),
                'total_old_price_bulky' => $bulkyDocument->bulkySales()->sum('old_price_bulky_sale'),
                'after_price_bulky' => $bulkyDocument->bulkySales()->sum('after_price_bulky_sale'),
            ]);

            DB::commit();

            $updatedDocument = BulkyDocument::with('bulkySales')->find($bulkyDocument->id);
            return (new ResponseResource(true, "Data berhasil dihapus dari karung!", $updatedDocument))->response();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting bulky sale: ' . $e->getMessage());
            return (new ResponseResource(false, "Sistem gagal menghapus data!", []))->response()->setStatusCode(500);
        }
    }
    public function productsCargo(Request $request)
    {
        $searchQuery = $request->input('q');

        $bulkyDocumentId = $request->input('bulky_document_id');
        $activeBagType = null;
        $activeBagValue = null;
        $bagWords = [];

        if ($bulkyDocumentId) {
            $user = auth()->user();
            $activeBag = BagProducts::where('user_id', $user->id)
                ->where('bulky_document_id', $bulkyDocumentId)
                ->where('status', 'process')
                ->first();

            if ($activeBag) {
                $activeBagType = $activeBag->type;
                $activeBagValue = strtolower(trim($activeBag->category_bag));

                if ($activeBagType === 'category') {
                    $cleanBagValue = trim(preg_replace('/[^a-z]+/i', ' ', $activeBagValue));
                    $bagWords = array_filter(explode(' ', $cleanBagValue));
                }
            }
        }

        $productSaleBarcodes = Sale::where('status_sale', 'proses')->pluck('product_barcode_sale')->toArray();

        if ($activeBagType === 'color') {
            $colorQuery = New_product::whereNotIn('new_barcode_product', $productSaleBarcodes)
                ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
                ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
                ->where('new_tag_product', $activeBagValue)
                ->select(
                    'new_barcode_product as barcode',
                    'new_name_product as name',
                    'new_category_product as category',
                    'created_at as created_date'
                );

            $bundleQuery = Bundle::whereNotIn('barcode_bundle', $productSaleBarcodes)
                ->whereNotNull('name_color')
                ->where('name_color', $activeBagValue)
                ->where('product_status', 'not sale')
                ->select(
                    'barcode_bundle as barcode',
                    'name_bundle as name',
                    'category as category',
                    'created_at as created_date'
                );

            if ($searchQuery) {
                $colorQuery->where(function ($query) use ($searchQuery) {
                    $query->where('new_barcode_product', 'like', '%' . $searchQuery . '%')
                        ->orWhere('new_name_product', 'like', '%' . $searchQuery . '%')
                        ->orWhere('new_tag_product', 'like', '%' . $searchQuery . '%');
                });

                $bundleQuery->where(function ($query) use ($searchQuery) {
                    $query->where('barcode_bundle', 'like', '%' . $searchQuery . '%')
                        ->orWhere('name_bundle', 'like', '%' . $searchQuery . '%')
                        ->orWhere('name_color', 'like', '%' . $searchQuery . '%');
                });
            }

            $mergedQuery = $colorQuery->unionAll($bundleQuery)->orderBy('created_date', 'desc');

            $products = $mergedQuery->paginate(15);

            return (new ResponseResource(true, "List data product color", $products))->response();
        }

        $newProductsQuery = New_product::whereNotIn('new_barcode_product', $productSaleBarcodes)
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
            ->select('new_barcode_product as barcode', 'new_name_product as name', 'new_category_product as category', 'created_at as created_date');

        $stagingProductsQuery = StagingProduct::whereNotIn('new_barcode_product', $productSaleBarcodes)
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
            ->select('new_barcode_product as barcode', 'new_name_product as name', 'new_category_product as category', 'created_at as created_date');

        $bundleQuery = Bundle::whereNot('type', 'type2')
            ->where('product_status', '=', 'not sale')
            ->select('barcode_bundle as barcode', 'name_bundle as name', 'category', 'created_at as created_date');

        if ($activeBagType === 'category') {
            if (!empty($bagWords)) {
                $newProductsQuery->where(function ($q) use ($bagWords) {
                    foreach ($bagWords as $word) {
                        $q->orWhere('new_category_product', 'LIKE', '%' . $word . '%');
                    }
                });

                $stagingProductsQuery->whereNull('new_tag_product')->where(function ($q) use ($bagWords) {
                    foreach ($bagWords as $word) {
                        $q->orWhere('new_category_product', 'LIKE', '%' . $word . '%');
                    }
                });

                $bundleQuery->where(function ($q) use ($bagWords) {
                    foreach ($bagWords as $word) {
                        $q->orWhere('category', 'LIKE', '%' . $word . '%');
                    }
                });
            } else {
                $newProductsQuery->where('new_category_product', $activeBagValue);
                $stagingProductsQuery->where('new_category_product', $activeBagValue)->whereNull('new_tag_product');
                $bundleQuery->where('category', $activeBagValue);
            }
        } else {
            $newProductsQuery->whereNotNull('new_category_product');
            $stagingProductsQuery->whereNotNull('new_category_product')->whereNull('new_tag_product');
        }

        if ($searchQuery) {
            $newProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'like', '%' . $searchQuery . '%');
            });

            $stagingProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_name_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%');
            });

            $bundleQuery->where(function ($query) use ($searchQuery) {
                $query->where('barcode_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('name_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('category', 'like', '%' . $searchQuery . '%');
            });
        }

        $products = $newProductsQuery->union($stagingProductsQuery)->union($bundleQuery)
            ->orderBy('created_date', 'desc')
            ->paginate(15);

        return (new ResponseResource(true, "List data product category", $products))->response();
    }
}
