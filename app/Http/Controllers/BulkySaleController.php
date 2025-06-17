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
                        ],
                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'old_price' => $model->total_price_bundle,
                            'status' => $model->product_status,
                        ],
                    };

                    match ($type) {
                        'new_product', 'staging_product' => $model->update(['new_status_product' => 'sale']),
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
    public function destroy(BulkySale $bulkySale)
    {
        try {
            $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')
                ->where('user_id', auth()->id())
                ->where('id', $bulkySale->bulky_document_id)
                ->first();

            if ($bulkyDocument) {
                $models = [
                    'new_product' => New_product::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                    'staging_product' => StagingProduct::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                    'bundle_product' => Bundle::where('barcode_bundle', $bulkySale->barcode_bulky_sale)->first(),
                ];

                foreach ($models as $type => $model) {
                    if (!$models) continue;

                    match ($type) {
                        'new_product', 'staging_product' => $model->update(['new_status_product' => $bulkySale->status_product_before]),
                        'bundle_product' => $model->update(['product_status' => $bulkySale->status_product_before]),
                    };

                    break;
                }

                if ($bulkyDocument->bulkySales->count() === 1) {
                    $bulkyDocument->delete();
                } else {
                    $bulkySale->delete();
                }
            } else {
                return (new ResponseResource(false, "Data tidak ditemukan!", []))->response()->setStatusCode(404);
            }
            return new ResponseResource(true, "Data berhasil dihapus!", $bulkyDocument->load('bulkySales'));
        } catch (\Exception $e) {
            Log::error('Error deleting bulky sale: ' . $e->getMessage());
            return (new ResponseResource(true, "Data gagal dihapus!", []))->response()->setStatusCode(422);
        }
    }

    public function store2(Request $request)
    {
        $user = auth()->user();
        $bulkyDocument = BulkyDocument::find($request->bulky_document_id);
        if (!$bulkyDocument) {
            $resource = new ResponseResource(false, "Data bulky tidak ditemukan!", null);
            return $resource->response()->setStatusCode(404);
        }
        if ($bulkyDocument->status_bulky !== 'proses') {
            $resource = new ResponseResource(false, "Data bulky sudah selesai!", null);
            return $resource->response()->setStatusCode(400);
        }

        $validator = Validator::make($request->all(), [
            'file_import' => 'nullable|file|mimes:xlsx,xls,csv|max:1536',
            'barcode_product' => 'nullable|required_without:file_import',

        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }


        $bagProduct = BagProducts::where('user_id', $user->id)
            ->where('bulky_document_id', $bulkyDocument->id)
            ->first();

        if (!$bagProduct) {
            $bagProduct = BagProducts::create([
                'user_id' => $user->id,
                'bulky_document_id' => $bulkyDocument->id,
                'total_product' => 0,
            ]);
        }


        if ($request->hasFile('file_import')) {
            $import = new BulkySaleImport2($bulkyDocument->id, $bulkyDocument->discount_bulky, $bagProduct->id);

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

            $bagProduct->update([
                'total_product' => $bagProduct->bulkySales->count(),
            ]);

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
                        ],
                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'old_price' => $model->total_price_bundle,
                            'status' => $model->product_status,
                        ],
                    };

                    match ($type) {
                        'new_product', 'staging_product' => $model->update(['new_status_product' => 'sale']),
                        'bundle_product' => $model->update(['product_status' => 'sale']),
                    };

                    break;
                }

                if (!$product) {
                    return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
                }

                $afterPriceBulkySale = $product['old_price'] - ($product['old_price'] * $bulkyDocument->discount_bulky / 100);
                $bulkySale = BulkySale::create([
                    'bulky_document_id' => $bulkyDocument->id ?? null,
                    'barcode_bulky_sale' => $request->input('barcode_product'),
                    'product_category_bulky_sale' => $product['category'] ?? null,
                    'name_product_bulky_sale' => $product['name'] ?? null,
                    'old_price_bulky_sale' => $product['old_price'] ?? null,
                    'status_product_before' => $product['status'],
                    'after_price_bulky_sale' => $afterPriceBulkySale,
                    'bag_product_id' => $bagProduct->id ?? null,
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

    public function destroy2(BulkySale $bulkySale)
    {
        try {
            $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')
                ->where('user_id', auth()->id())
                ->where('id', $bulkySale->bulky_document_id)
                ->first();
            $bagProduct = BagProducts::where('id', $bulkySale->bag_product_id)->first();

            if ($bulkyDocument) {
                $models = [
                    'new_product' => New_product::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                    'staging_product' => StagingProduct::where('new_barcode_product', $bulkySale->barcode_bulky_sale)->first(),
                    'bundle_product' => Bundle::where('barcode_bundle', $bulkySale->barcode_bulky_sale)->first(),
                ];
                foreach ($models as $type => $model) {
                    // Hanya lanjut jika model ditemukan
                    if ($model) {
                        match ($type) {
                            'new_product', 'staging_product' => $model->update(['new_status_product' => $bulkySale->status_product_before]),
                            'bundle_product' => $model->update(['product_status' => $bulkySale->status_product_before]),
                        };
                        break; // keluar dari loop setelah update pada yang pertama
                    }
                }

                if ($bulkyDocument->bulkySales->count() === 1) {
                    $bulkyDocument->delete();
                } else {
                    $bulkySale->delete();
                    if ($bagProduct) {
                        $bagProduct->decrement('total_product');
                    }
                }
            } else {
                return (new ResponseResource(false, "Data tidak ditemukan!", []))->response()->setStatusCode(404);
            }
            return new ResponseResource(true, "Data berhasil dihapus!", $bulkyDocument->load('bulkySales'));
        } catch (\Exception $e) {
            Log::error('Error deleting bulky sale: ' . $e->getMessage());
            return (new ResponseResource(true, "Data gagal dihapus!", []))->response()->setStatusCode(422);
        }
    }
}
