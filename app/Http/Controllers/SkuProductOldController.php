<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\SkuDocument;
use App\Models\SkuProduct;
use App\Models\SkuProductOld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
            $stokAwal = $product->old_quantity_product;

            $totalInput = $product->actual_quantity_product +
                $product->damaged_quantity_product +
                $product->lost_quantity_product;

            if ($totalInput !== $stokAwal) {
                $selisih = $totalInput - $stokAwal;
                $status = $selisih > 0 ? "Kelebihan $selisih" : "Kurang " . abs($selisih);

                $invalidProducts[] = [
                    'barcode' => $product->old_barcode_product,
                    'name' => $product->old_name_product,
                    'stok_awal' => $stokAwal,
                    'total_input' => $totalInput,
                    'masalah' => "Perhitungan tidak sesuai ($status item)",
                    'rincian' => "Actual: {$product->actual_quantity_product}, Damaged: {$product->damaged_quantity_product}, Lost: {$product->lost_quantity_product}"
                ];
                continue;
            }

            if ($stokAwal > 0 && $totalInput === 0) {
                $invalidProducts[] = [
                    'barcode' => $product->old_barcode_product,
                    'name' => $product->old_name_product,
                    'masalah' => "Belum divalidasi (Semua nilai masih 0)",
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

            $dataToInsert = [];
            $timestamp = now();

            foreach ($oldProducts as $old) {
                $dataToInsert[] = [
                    'code_document' => $old->code_document,
                    'barcode_product' => $old->old_barcode_product,
                    'name_product' => $old->old_name_product,
                    'price_product' => $old->old_price_product,
                    'quantity_product' => $old->actual_quantity_product,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($dataToInsert, 500) as $chunk) {
                SkuProduct::insert($chunk);
            }

            $document->update(['status_document' => 'done']);

            DB::commit();

            return new ResponseResource(true, "Validasi Sukses. Produk berhasil disubmit ke list final.", [
                'total_moved' => count($dataToInsert),
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

            $stokAwal = $product->old_quantity_product;
            
            $totalDitemukan = $request->actual_quantity_product + $request->damaged_quantity_product;

            if ($totalDitemukan > $stokAwal) {
                $kelebihan = $totalDitemukan - $stokAwal;
                return (new ResponseResource(
                    false,
                    "Validasi Gagal: Total barang ($totalDitemukan) melebihi Stok Awal ($stokAwal). Kelebihan $kelebihan item.",
                    [
                        'stok_awal' => $stokAwal,
                        'total_input' => $totalDitemukan,
                        'kelebihan' => $kelebihan
                    ]
                ))->response()->setStatusCode(422);
            }

            $qtyLost = $stokAwal - $totalDitemukan;

            $product->update([
                'actual_quantity_product' => $request->actual_quantity_product,
                'damaged_quantity_product' => $request->damaged_quantity_product,
                'lost_quantity_product' => $qtyLost,
            ]);

            DB::commit();

            return new ResponseResource(true, "Data berhasil diperbarui. Lost Qty terhitung otomatis: $qtyLost", $product);

        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal memperbarui data", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }
}
