<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\New_product;
use App\Models\SkuDocument;
use App\Models\SkuProduct;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SkuProductController extends Controller
{
    public function index(Request $request)
    {
        $codeDocument = $request->input('code_document');
        $keyword = $request->input('q');

        if (!$codeDocument) {
            return (new ResponseResource(false, "Parameter 'code_document' wajib diisi.", null))
                ->response()
                ->setStatusCode(400);
        }

        $query = SkuProduct::where('code_document', $codeDocument);

        if ($keyword) {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('barcode_product', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('name_product', 'LIKE', '%' . $keyword . '%');
            });
        }

        $products = $query->latest()->paginate(50);

        $document = SkuDocument::where('code_document', $codeDocument)->first();

        if (!$document) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan.", null))
                ->response()
                ->setStatusCode(404);
        }

        foreach ($products as $product) {
            $product->custom_barcode = $document->custom_barcode ?? null;
        }

        return new ResponseResource(true, "List Produk Final (SKU)", [
            'document_info' => [
                'code_document' => $document->code_document,
                'base_document' => $document->base_document,
                'status' => $document->status_document,
                'total_items' => $document->total_column_in_document,
                'custom_barcode' => $document->custom_barcode,
                'submitted_at' => $document->updated_at,
            ],
            'products' => $products
        ]);
    }

    public function show($id)
    {
        $product = SkuProduct::find($id);

        if (!$product) {
            return (new ResponseResource(false, "Produk tidak ditemukan", null))
                ->response()
                ->setStatusCode(404);
        }

        return new ResponseResource(true, "Detail Produk SKU", $product);
    }

    public function storeBundle(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'items_per_bundle' => 'required|integer|min:1',
            'bundle_quantity' => 'required|integer|min:1',
            'new_category_product' => 'nullable|exists:categories,name_category',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $product = SkuProduct::lockForUpdate()->find($id);
            if (!$product) {
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }

            $itemsPerBundle = $request->items_per_bundle;
            $bundleQty = $request->bundle_quantity;
            $totalQtyNeeded = $itemsPerBundle * $bundleQty;

            if ($product->quantity_product < $totalQtyNeeded) {
                return response()->json([
                    'message' => 'Stok actual tidak mencukupi.',
                    'butuh' => $totalQtyNeeded,
                    'sisa' => $product->quantity_product
                ], 400);
            }

            $unitPrice = $product->price_product;
            $bundlePrice = $unitPrice * $itemsPerBundle;

            $document = SkuDocument::where('code_document', $product->code_document)->first();
            $customBarcode = $document ? $document->custom_barcode : null;
            $userId = auth()->id();

            $insertedData = [];
            $timestamp = now();

            $qualityJson = $this->makeQualityJson('lolos');

            if ($bundlePrice >= 100000) {

                if (!$request->has('new_category_product') || empty($request->new_category_product)) {
                    return response()->json(['message' => 'Untuk harga bundle >= 100.000, wajib memilih kategori.'], 422);
                }

                $category = Category::where('name_category', $request->new_category_product)->first();
                $discount = $category ? $category->discount_category : 0;
                $finalPrice = $bundlePrice - ($bundlePrice * ($discount / 100));

                for ($i = 0; $i < $bundleQty; $i++) {
                    $newBarcode = $this->generateBarcodeWithCustom($customBarcode, $userId, $request->new_category_product);

                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => "Bundling " . $product->name_product,
                        'new_quantity_product' => 1,
                        'new_price_product' => $finalPrice,
                        'old_price_product' => $unitPrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_category_product' => $category->name_category,
                        'new_tag_product' => null,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'display_price' => $finalPrice,
                        'new_discount' => $discount,
                        'type' => 'type1',
                    ];
                }

                foreach (array_chunk($insertedData, 500) as $chunk) {
                    StagingProduct::insert($chunk);
                }
                $destination = 'Staging Product';
            } else {

                $colorTag = Color_tag::where('min_price_color', '<=', $bundlePrice)
                    ->where('max_price_color', '>=', $bundlePrice)
                    ->where(function ($q) {
                        $q->where('name_color', 'LIKE', '%Big%')
                            ->orWhere('name_color', 'LIKE', '%Small%');
                    })
                    ->first();

                if (!$colorTag) {
                    return response()->json(['message' => 'Tidak ditemukan Color Tag (Big/Small) untuk range harga ini.'], 422);
                }

                $fixPrice = $colorTag->fixed_price_color;
                $tagName = $colorTag->name_color;

                for ($i = 0; $i < $bundleQty; $i++) {
                    $newBarcode = $this->generateBarcodeWithCustom($customBarcode, $userId, null);

                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => "Bundling " . $product->name_product,
                        'new_quantity_product' => 1,
                        'new_price_product' => $fixPrice,
                        'display_price' => $fixPrice,
                        'old_price_product' => $unitPrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_tag_product' => $tagName,
                        'new_category_product' => null,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'new_discount' => 0,
                        'type' => 'type1',
                    ];
                }

                foreach (array_chunk($insertedData, 500) as $chunk) {
                    New_product::insert($chunk);
                }
                $destination = 'New Product';
            }

            $product->decrement('quantity_product', $totalQtyNeeded);

            DB::commit();

            return new ResponseResource(true, "Berhasil membuat $bundleQty bundle.", [
                'total_item_used' => $totalQtyNeeded,
                'first_price_bundle' => $bundlePrice,
                'destination' => $destination
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function storeDamaged(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'damaged_quantity' => 'required|integer|min:1',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $product = SkuProduct::lockForUpdate()->find($id);

            if (!$product) {
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }

            $qtyNeeded = $request->damaged_quantity;

            if ($product->quantity_product < $qtyNeeded) {
                return response()->json([
                    'message' => 'Stok actual tidak mencukupi untuk damaged.',
                    'sisa' => $product->quantity_product
                ], 400);
            }

            $product->decrement('quantity_product', $qtyNeeded);

            $timestamp = now();
            $insertedData = [];
            $userId = auth()->id();

            $qualityJson = $this->makeQualityJson('damaged', $request->deskripsi);

            for ($i = 0; $i < $qtyNeeded; $i++) {
                $insertedData[] = [
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->barcode_product,
                    'new_barcode_product' => generateNewBarcode(null),
                    'new_name_product' => $product->name_product,
                    'new_quantity_product' => 1,
                    'new_price_product' => $product->price_product,
                    'old_price_product' => $product->price_product,
                    'new_status_product' => 'display',
                    'new_quality' => $qualityJson,
                    'actual_new_quality' => $qualityJson,
                    'user_id' => $userId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'display_price' => $product->price_product,
                    'type' => 'type1',
                ];
            }

            foreach (array_chunk($insertedData, 500) as $chunk) {
                StagingProduct::insert($chunk);
            }

            DB::commit();

            return new ResponseResource(true, "Berhasil memproses barang rusak ($qtyNeeded item)", [
                'sisa_stok' => $product->quantity_product
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function generateBarcodeWithCustom($customBarcode, $userId, $category = null)
    {
        if ($customBarcode) {
            return newBarcodeCustom($customBarcode, $userId);
        } else {
            return generateNewBarcode($category);
        }
    }

    private function makeQualityJson($status, $description = null)
    {
        return json_encode([
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? ($description ?? 'damaged') : null,
            'abnormal' => $status === 'abnormal' ? ($description ?? 'abnormal') : null,
            'non' => $status === 'non' ? ($description ?? 'non') : null,
        ]);
    }
}
