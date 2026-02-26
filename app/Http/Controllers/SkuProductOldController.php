<?php

namespace App\Http\Controllers;

use App\Exports\SkuProductOldExport;
use App\Http\Resources\ResponseResource;
use App\Models\RiwayatCheck;
use App\Models\SkuDocument;
use App\Models\SkuProduct;
use App\Models\SkuProductOld;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class SkuProductOldController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');
        $search = $request->input('search');

        $perPage = $request->input('per_page', 50);

        $code_documents = SkuProductOld::where('code_document', $search)
            ->where(function ($subQuery) use ($query) {
                $subQuery->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_name_product', 'LIKE', '%' . $query . '%');
            })
            ->paginate($perPage);

        $document = SkuDocument::where('code_document', $search)->first();

        if ($document) {
            foreach ($code_documents as $code_document) {
                $code_document->custom_barcode = $document->custom_barcode ?? null;
            }

            return new ResponseResource(true, "Data Document products", [
                'document_name' => $document->base_document ?? 'N/A',
                'status' => $document->status_document ?? 'N/A',
                'total_columns' => $document->total_column_in_document ?? 0,
                'custom_barcode' => $document->custom_barcode ?? null,
                'code_document' => $document->code_document ?? 'N/A',
                'data' => $code_documents ?? null,
            ]);
        } else {
            return (new ResponseResource(false, "code document tidak ada", null))
                ->response()
                ->setStatusCode(404);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|exists:sku_documents,code_document',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $codeDocument = $request->input('code_document');

        $oldProducts = SkuProductOld::where('code_document', $codeDocument)->get();

        if ($oldProducts->isEmpty()) {
            return (new ResponseResource(false, "Tidak ada produk untuk disubmit.", null))
                ->response()
                ->setStatusCode(404);
        }

        $invalidProducts = [];

        foreach ($oldProducts as $product) {
            $initialStock = $product->old_quantity_product;

            $totalInput = $product->actual_quantity_product +
                $product->damaged_quantity_product +
                $product->lost_quantity_product;

            if ($totalInput !== $initialStock) {
                $discrepancy = $totalInput - $initialStock;

                $status = $discrepancy > 0 ? "Excess $discrepancy" : "Missing " . abs($discrepancy);

                $invalidProducts[] = [
                    'barcode' => $product->old_barcode_product,
                    'name' => $product->old_name_product,
                    'initial_stock' => $initialStock,
                    'total_input' => $totalInput,
                    'issue' => "Perhitungan tidak sesuai ($status item)", 
                    'details' => "Actual: {$product->actual_quantity_product}, Damaged: {$product->damaged_quantity_product}, Lost: {$product->lost_quantity_product}" // Sebelumnya 'rincian'
                ];
                continue;
            }

            if ($initialStock > 0 && $totalInput === 0) {
                $invalidProducts[] = [
                    'barcode' => $product->old_barcode_product,
                    'name' => $product->old_name_product,
                    'issue' => "Belum divalidasi (Semua nilai masih 0)",
                ];
            }
        }

        if (count($invalidProducts) > 0) {
            return (new ResponseResource(false, "Gagal Submit: Terdapat " . count($invalidProducts) . " produk yang perhitungannya belum sesuai.", [
                'invalid_count' => count($invalidProducts),
                'list_invalid_products' => $invalidProducts
            ]))->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $document = SkuDocument::where('code_document', $codeDocument)->first();

            if ($document->status_document === 'done') {
                return (new ResponseResource(false, "Dokumen ini sudah disubmit sebelumnya.", null))
                    ->response()
                    ->setStatusCode(400);
            }

            $skuDataToInsert = [];
            $stagingDamagedData = [];
            $timestamp = now();
            $userId = auth()->id();

            $qualityDamaged = json_encode([
                "lolos" => null,
                "damaged" => "damaged",
                "abnormal" => null
            ]);

            foreach ($oldProducts as $old) {
                if ($old->actual_quantity_product > 0) {
                    $skuDataToInsert[] = [
                        'code_document' => $old->code_document,
                        'barcode_product' => $old->old_barcode_product,
                        'name_product' => $old->old_name_product,
                        'price_product' => $old->old_price_product,
                        'quantity_product' => $old->actual_quantity_product,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($old->damaged_quantity_product > 0) {
                    for ($i = 0; $i < $old->damaged_quantity_product; $i++) {
                        $newBarcode = generateNewBarcode(null);
                        $stagingDamagedData[] = [
                            'code_document' => $old->code_document,
                            'old_barcode_product' => $old->old_barcode_product,
                            'new_barcode_product' => $newBarcode,
                            'new_name_product' => $old->old_name_product,
                            'new_quantity_product' => 1,
                            'new_price_product' => $old->old_price_product,
                            'old_price_product' => $old->old_price_product,
                            'new_status_product' => 'display',
                            'new_quality' => $qualityDamaged,
                            'actual_new_quality' => $qualityDamaged,
                            'new_category_product' => null,
                            'new_tag_product' => null,
                            'new_discount' => 0,
                            'new_date_in_product' => $timestamp,
                            'user_id' => $userId,
                            'display_price' => $old->old_price_product,
                            'type' => 'type1',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }
            }

            foreach (array_chunk($skuDataToInsert, 500) as $chunk) {
                SkuProduct::insert($chunk);
            }

            foreach (array_chunk($stagingDamagedData, 500) as $chunk) {
                StagingProduct::insert($chunk);
            }

            $document->update(['status_document' => 'done']);

            $this->createRiwayatCheck($userId, $codeDocument);

            DB::commit();

            return new ResponseResource(true, "Validasi Sukses. Produk berhasil disubmit.", [
                'total_moved_to_sku' => count($skuDataToInsert),
                'total_moved_to_staging_damaged' => count($stagingDamagedData),
                'code_document' => $codeDocument
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Submit SKU Error: " . $e->getMessage());
            return (new ResponseResource(false, "Terjadi kesalahan server saat submit", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function show($id)
    {
        $product = SkuProductOld::find($id);

        if (!$product) {
            return (new ResponseResource(false, "Produk tidak ditemukan", null))
                ->response()
                ->setStatusCode(404);
        }

        $document = SkuDocument::where('code_document', $product->code_document)->first();

        $product->custom_barcode = $document->custom_barcode ?? null;
        $product->document_status = $document->status_document ?? null;

        return new ResponseResource(true, "Detail Data Produk", $product);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'actual_quantity_product' => 'required|integer|min:0',
            'damaged_quantity_product' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $product = SkuProductOld::find($id);

            if (!$product) {
                return (new ResponseResource(false, "Produk tidak ditemukan", null))
                    ->response()
                    ->setStatusCode(404);
            }

            $document = SkuDocument::where('code_document', $product->code_document)->first();

            if ($document && $document->status_document === 'done') {
                return (new ResponseResource(false, "Gagal Edit: Dokumen ini sudah berstatus 'Done' (Selesai). Data tidak dapat diubah lagi.", null))
                    ->response()
                    ->setStatusCode(422);
            }

            $initialStock = $product->old_quantity_product;

            $totalFound = $request->actual_quantity_product + $request->damaged_quantity_product;

            if ($totalFound > $initialStock) {
                $excess = $totalFound - $initialStock;
                return (new ResponseResource(
                    false,
                    "Validasi Gagal: Total barang ($totalFound) melebihi Stok Awal ($initialStock). Kelebihan $excess item.",
                    [
                        'initial_stock' => $initialStock,
                        'total_found' => $totalFound,
                        'excess' => $excess
                    ]
                ))->response()->setStatusCode(422);
            }

            // Calculate Lost Quantity
            $lostQuantity = $initialStock - $totalFound;

            $product->update([
                'actual_quantity_product' => $request->actual_quantity_product,
                'damaged_quantity_product' => $request->damaged_quantity_product,
                'lost_quantity_product' => $lostQuantity,
            ]);

            DB::commit();

            return new ResponseResource(true, "Data berhasil diperbarui. Lost Qty terhitung otomatis: $lostQuantity", $product);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal memperbarui data", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function export($id)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $doc = SkuDocument::find($id);

            if (!$doc) {
                return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            $codeDocument = $doc->code_document;

            $hasData = SkuProductOld::where('code_document', $codeDocument)->exists();
            if (!$hasData) {
                return (new ResponseResource(false, "Data produk tidak ditemukan untuk dokumen ini", null))
                    ->response()->setStatusCode(404);
            }

            $folderName = 'exports/sku_product_old';
            $safeCode = str_replace(['/', '\\', ' '], '-', $codeDocument);
            $fileName = $safeCode . '_' . date('Ymd_His') . '.xlsx';
            $filePath = $folderName . '/' . $fileName;

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new SkuProductOldExport($codeDocument), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return (new ResponseResource(true, "File berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name' => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    private function createRiwayatCheck($userId, $code_document)
    {
        $totalPrice = SkuProductOld::where('code_document', $code_document)->sum('old_price_product');
        $doc = SkuDocument::where('code_document', $code_document)->first();

        RiwayatCheck::create([
            'user_id' => $userId,
            'code_document' => $code_document,
            'base_document' => $doc->base_document ?? 'SKU Import',
            'total_data' => $doc->total_column_in_document ?? 0,
            'status_approve' => 'done',
            'total_price' => $totalPrice,
            'percentage_in' => 0,
            'status_file' => true,
            'total_data_in' => 0,
            'total_data_lolos' => 0,
            'total_data_damaged' => 0,
            'total_data_abnormal' => 0,
            'total_discrepancy' => $doc->total_column_in_document ?? 0,
            'precentage_total_data' => 0,
            'percentage_lolos' => 0,
            'percentage_damaged' => 0,
            'percentage_abnormal' => 0,
            'percentage_discrepancy' => 100,
            'value_data_lolos' => 0,
            'value_data_damaged' => 0,
            'value_data_abnormal' => 0,
            'value_data_discrepancy' => 0
        ]);
    }
}
