<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\HistoryBundling;
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
        $perPage = $request->input('per_page', 50);

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

        $products = $query->latest()->paginate($perPage);

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

            $qtyBefore = $product->quantity_product;
            $totalValueBefore = $qtyBefore * $unitPrice;

            $document = SkuDocument::where('code_document', $product->code_document)->first();
            $customBarcode = $document ? $document->custom_barcode : null;
            $userId = auth()->id();

            $insertedData = [];
            $generatedProducts = [];
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
                    $generatedProducts[] = [
                        'new_barcode_product' => $newBarcode,
                        'new_price_product' => $finalPrice,
                        'old_price_product' => $bundlePrice,
                        'category' => $category->name_category,
                        'tag' => null
                    ];
                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => "Bundling " . $product->name_product,
                        'new_quantity_product' => 1,
                        'new_price_product' => $finalPrice,
                        'old_price_product' => $bundlePrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_category_product' => $category->name_category,
                        'new_tag_product' => null,
                        'new_discount' => 0,
                        'new_date_in_product' => $timestamp,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'display_price' => $finalPrice,
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
                        $q->where('name_color', 'LIKE', '%Big%')->orWhere('name_color', 'LIKE', '%Small%');
                    })->first();

                if (!$colorTag) {
                    return response()->json(['message' => 'Tidak ditemukan Color Tag (Big/Small) untuk range harga ini.'], 422);
                }
                $fixPrice = $colorTag->fixed_price_color;
                $tagName = $colorTag->name_color;

                for ($i = 0; $i < $bundleQty; $i++) {
                    $newBarcode = $this->generateBarcodeWithCustom($customBarcode, $userId, null);
                    $generatedProducts[] = [
                        'new_barcode_product' => $newBarcode,
                        'new_price_product' => $fixPrice,
                        'old_price_product' => $bundlePrice,
                        'category' => null,
                        'tag' => $tagName
                    ];
                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => "Bundling " . $product->name_product,
                        'new_quantity_product' => 1,
                        'new_price_product' => $fixPrice,
                        'display_price' => $fixPrice,
                        'old_price_product' => $bundlePrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_tag_product' => $tagName,
                        'new_discount' => 0,
                        'new_date_in_product' => $timestamp,
                        'new_category_product' => null,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'type' => 'type1',
                    ];
                }
                foreach (array_chunk($insertedData, 500) as $chunk) {
                    New_product::insert($chunk);
                }
                $destination = 'New Product';
            }

            $product->decrement('quantity_product', $totalQtyNeeded);

            $qtyAfter = $product->fresh()->quantity_product;
            $totalValueAfter = $qtyAfter * $unitPrice;

            HistoryBundling::create([
                'user_id' => $userId,
                'code_document' => $product->code_document,
                'barcode_product' => $product->barcode_product,
                'name_product' => $product->name_product,

                'price_before' => $totalValueBefore,
                'price_after' => $totalValueAfter,

                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,

                'total_qty_bundle' => $bundleQty,
                'items_per_bundle' => $itemsPerBundle,
                'type' => 'bundling'
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil membuat $bundleQty bundle.", [
                'total_item_used' => $totalQtyNeeded,
                'destination' => $destination,
                'generated_products' => $generatedProducts
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

            $qtyBefore = $product->quantity_product;
            $unitPrice = $product->price_product;
            $totalValueBefore = $qtyBefore * $unitPrice;

            $product->decrement('quantity_product', $qtyNeeded);

            $qtyAfter = $product->fresh()->quantity_product;
            $totalValueAfter = $qtyAfter * $unitPrice;

            HistoryBundling::create([
                'user_id' => auth()->id(),
                'code_document' => $product->code_document,
                'barcode_product' => $product->barcode_product,
                'name_product' => $product->name_product,
                'price_before' => $totalValueBefore,
                'price_after' => $totalValueAfter,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'total_qty_bundle' => 0,
                'items_per_bundle' => 0,
                'type' => 'damaged'
            ]);

            $timestamp = now();
            $insertedData = [];
            $userId = auth()->id();
            $qualityJson = $this->makeQualityJson('damaged', $request->description);

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
                    'new_date_in_product' => $timestamp,
                    'actual_new_quality' => $qualityJson,
                    'new_discount' => 0,
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
                'sisa_stok' => $qtyAfter
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function checkBundlePrice(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'items_per_bundle' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $product = SkuProduct::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan'], 404);
        }

        $itemsPerBundle = $request->items_per_bundle;
        $unitPrice = $product->price_product;
        $bundlePrice = $unitPrice * $itemsPerBundle;

        $response = [
            'tag_name' => null,
            'fixed_price' => 0,
            'hex_color' => null,
        ];

        if ($bundlePrice < 100000) {

            $colorTag = Color_tag::where('min_price_color', '<=', $bundlePrice)
                ->where('max_price_color', '>=', $bundlePrice)
                ->where(function ($q) {
                    $q->where('name_color', 'LIKE', '%Big%')
                        ->orWhere('name_color', 'LIKE', '%Small%');
                })
                ->first();

            if ($colorTag) {
                $response['tag_name'] = $colorTag->name_color;
                $response['fixed_price'] = $colorTag->fixed_price_color;
                $response['hex_color'] = $colorTag->hexa_code_color;
            } else {
                $response['tag_name'] = 'Unknown Tag';
            }
        }

        return new ResponseResource(true, "Check price success", $response);
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

    public function getHistoryBundling(Request $request)
    {
        $search = $request->input('q');
        $perPage = $request->input('per_page', 10);

        $query = HistoryBundling::with('user:id,name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('barcode_product', 'LIKE', '%' . $search . '%');
            });
        }

        $histories = $query->latest()->paginate($perPage);

        $formattedData = $histories->getCollection()->map(function ($item) {
            return [
                'id' => $item->id,
                'tanggal' => $item->created_at->format('Y-m-d H:i:s'),
                'user' => $item->user->name ?? 'Unknown',
                'produk' => $item->name_product,
                'price_before' => number_format($item->price_before, 2),
                'price_after' => number_format($item->price_after, 2),
                'qty_before' => $item->qty_before,
                'qty_after' => $item->qty_after,
                'bundling' => $item->type === 'damaged' ? '-' : $item->total_qty_bundle,
                'items_per_bundle' => $item->type === 'damaged' ? '-' : $item->items_per_bundle, 
                'type_badge' => $item->type
            ];
        });

        $histories->setCollection($formattedData);

        return new ResponseResource(true, "List History Bundling", $histories);
    }
}
