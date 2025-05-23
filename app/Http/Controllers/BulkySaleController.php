<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Imports\BulkySaleImport;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use App\Models\Bundle;
use App\Models\Buyer;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

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
            $import = new BulkySaleImport($bulkyDocument->id, $bulkyDocument->discount_bulky);

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
                "bulky_documents" => $bulkyDocument->load('bulkySales'),
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
                    if (!$models) continue;

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
                        ],
                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'old_price' => $model->total_price_bundle,
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
            $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')->where('user_id', auth()->id())->first();

            if ($bulkyDocument) {
                $bulkySale->delete();
                // $bulkyDocument->decrement('total_product_bulky');
                // $bulkyDocument->decrement('total_old_price_bulky', $bulkySale->old_price_bulky_sale);
                // $bulkyDocument->decrement('after_price_bulky', $bulkySale->after_price_bulky_sale);
            }
            return new ResponseResource(true, "Data berhasil dihapus!", $bulkyDocument->load('bulkySales'));
        } catch (\Exception $e) {
            Log::error('Error deleting bulky sale: ' . $e->getMessage());
            return (new ResponseResource(true, "Data gagal dihapus!", []))->response()->setStatusCode(422);
        }
    }
}
