<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\MigrateBulky;
use App\Models\MigrateBulkyProduct;
use App\Models\New_product;
use App\Models\Rack;
use App\Models\StagingProduct;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MigrateBulkyProductController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = $request->query('per_page', 30);
        $user = Auth::user();

        $migrateBulky = MigrateBulky::where('user_id', $user->id)
            ->where('status_bulky', 'proses')
            ->first();

        if (!$migrateBulky) {
            return new ResponseResource(true, "Data tidak ditemukan", null);
        }

        $productsQuery = $migrateBulky->migrateBulkyProducts()
            ->orderBy('updated_at', 'desc');

        if ($q) {
            $productsQuery->where(function ($query) use ($q) {
                $query->where('new_name_product', 'LIKE', '%' . $q . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $q . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $q . '%');
            });
        }

        $products = $productsQuery->paginate($perPage);

        $migrateBulkyArray = $migrateBulky->toArray();
        $migrateBulkyArray['migrate_bulky_products'] = $products;

        return new ResponseResource(true, "List data persiapan produk migrate!", $migrateBulkyArray);
    }

    public function listMigrateProducts(Request $request)
    {
        $query = $request->input('q');

        try {
            $blacklistBarcodes = MigrateBulkyProduct::whereHas('migrateBulky', function ($q) {
                $q->where('status_bulky', 'proses');
            })->pluck('new_barcode_product')->toArray();

            $productQuery = New_product::select(
                'id',
                'new_barcode_product',
                'new_name_product',
                'new_category_product',
                'new_price_product',
                'created_at',
                'new_status_product',
                'new_quality',
                'display_price',
                'new_date_in_product',
                DB::raw("'display' as source")
            )
                ->where('new_category_product', 'LIKE', '%ELEKTRONIK%')
                ->whereNotNull('new_category_product')
                ->where('new_tag_product', NULL)
                ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
                })
                ->where('new_status_product', '!=', 'migrate')
                ->whereNotIn('new_barcode_product', $blacklistBarcodes)
                ->where(function ($type) {
                    $type->whereNull('type')
                        ->orWhere('type', 'type1')
                        ->orWhere('type', 'type2');
                });

            $stagingQuery = StagingProduct::select(
                'id',
                'new_barcode_product',
                'new_name_product',
                'new_category_product',
                'new_price_product',
                'created_at',
                'new_status_product',
                'new_quality',
                'display_price',
                'new_date_in_product',
                DB::raw("'staging' as source")
            )
                ->where('new_category_product', 'LIKE', 'ELEKTRONIK%')
                ->whereNotNull('new_category_product')
                ->where('new_tag_product', NULL)
                ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
                })
                ->where('new_status_product', '!=', 'migrate')
                ->whereNotIn('new_barcode_product', $blacklistBarcodes)
                ->where(function ($type) {
                    $type->whereNull('type')
                        ->orWhere('type', 'type1')
                        ->orWhere('type', 'type2');
                });

            if ($query) {
                $productQuery->where(function ($queryBuilder) use ($query) {
                    $queryBuilder->where('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_status_product', 'LIKE', '%' . $query . '%');
                });

                $stagingQuery->where(function ($queryBuilder) use ($query) {
                    $queryBuilder->where('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_status_product', 'LIKE', '%' . $query . '%');
                });
            }

            $mergedQuery = $productQuery->unionAll($stagingQuery)
                ->orderBy('new_date_in_product', 'desc')
                ->paginate(33);

        } catch (\Exception $e) {
            return (new ResponseResource(false, "Data tidak ada", $e->getMessage()))
                ->response()
                ->setStatusCode(404);
        }

        return new ResponseResource(true, "List Electronic Products (Display & Staging)", $mergedQuery);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'  => 'required|integer',
            'source'      => 'required|in:staging,display',
            'description' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = null;
        if ($request->source === 'staging') {
            $product = StagingProduct::find($request->product_id);
        } else {
            $product = New_product::find($request->product_id);
        }

        if (!$product) {
            return response()->json(['errors' => ['product_id' => ['Produk tidak ditemukan di ' . $request->source]]], 404);
        }

        return $this->processMigration($request, $product, $request->source, $request->description);
    }

    public function storeByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode'     => 'required|string',
            'description' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $barcode = $request->barcode;
        $product = StagingProduct::where('new_barcode_product', $barcode)
            ->orWhere('old_barcode_product', $barcode)->first();
        $source = 'staging';

        if (!$product) {
            $product = New_product::where('new_barcode_product', $barcode)
                ->orWhere('old_barcode_product', $barcode)->first();
            $source = 'display';
        }

        if (!$product) {
            return response()->json(['errors' => ['barcode' => ['Produk tidak ditemukan dengan barcode tersebut.']]], 404);
        }

        if (stripos($product->new_category_product, 'ELEKTRONIK') === false) {
            return response()->json([
                'errors' => ['barcode' => ['Scan Gagal! Produk ini kategori "' . $product->new_category_product . '". Hanya kategori ELEKTRONIK yang diperbolehkan.']]
            ], 422);
        }

        return $this->processMigration($request, $product, $source, $request->description);
    }

    private function processMigration($request, $product, $source, $description)
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {
            $migrateBulky = MigrateBulky::firstOrCreate(
                ['user_id' => $user->id, 'status_bulky' => 'proses'],
                [
                    'name_user' => $user->name,
                    'code_document' => $this->generateCodeDocument(),
                    'total_product' => 0,
                    'total_price' => 0,
                ]
            );

            $isDuplicate = MigrateBulkyProduct::where('migrate_bulky_id', $migrateBulky->id)
                ->where('new_barcode_product', $product->new_barcode_product)
                ->exists();

            if ($isDuplicate) {
                return response()->json(['errors' => ['product_id' => ['Produk ini sudah ada di list migrasi Anda!']]], 422);
            }

            $previousRackId = $product->rack_id;
            $qualityData = [
                'lolos' => null, 'damaged' => null, 'abnormal' => null, 'migrate' => $description
            ];
            $jsonMigrate = json_encode($qualityData);

            $productData = $product->toArray();
            unset($productData['created_at'], $productData['updated_at']);

            $productData['migrate_bulky_id'] = $migrateBulky->id;
            $productData['new_product_id'] = $product->id;
            $productData['user_id'] = $user->id;
            $productData['new_status_product'] = 'migrate';
            $productData['new_quality'] = $jsonMigrate;
            $productData['code_document'] = $migrateBulky->code_document;
            $productData['new_discount'] = $product->new_discount ?? 0;
            $productData['display_price'] = $product->display_price ?? $product->new_price_product;

            MigrateBulkyProduct::create($productData);

            $product->update([
                'new_status_product' => 'migrate',
                'new_quality' => $jsonMigrate,
                'rack_id' => null
            ]);

            if ($previousRackId) {
                $rack = Rack::find($previousRackId);
                if ($rack) {
                    $productsInRack = ($source === 'staging') ? $rack->stagingProducts() : $rack->newProducts();
                    $rack->update([
                        'total_data' => $productsInRack->count(),
                        'total_new_price_product' => $productsInRack->sum('new_price_product'),
                        'total_old_price_product' => $productsInRack->sum('old_price_product'),
                        'total_display_price_product' => $productsInRack->sum('display_price'),
                    ]);
                }
            }

            DB::commit();

            return new ResponseResource(true, "Produk berhasil ditambahkan ke list migrate!", $migrateBulky);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal memproses data! " . $e->getMessage(), []);
        }
    }

    private function generateCodeDocument()
    {
        $lastCode = MigrateBulky::whereDate('created_at', today())
            ->max(DB::raw("CAST(SUBSTRING_INDEX(code_document, '/', 1) AS UNSIGNED)"));
        $newCode = str_pad(($lastCode + 1), 4, '0', STR_PAD_LEFT);
        return sprintf('%s/%s/%s', $newCode, date('m'), date('d'));
    }

    public function show($id)
    {
        try {
            $product = MigrateBulkyProduct::find($id);

            if (!$product) {
                return (new ResponseResource(false, "Produk tidak ditemukan di list migrate", null))
                    ->response()->setStatusCode(404);
            }

            $product->source = 'migrate';
            if (is_string($product->new_quality)) {
                $product->new_quality = json_decode($product->new_quality);
            }

            return new ResponseResource(true, "Detail Produk Migrate Bulky", $product);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $migrateProduct = MigrateBulkyProduct::findOrFail($id);
            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'code_document' => 'required',
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'condition' => 'required|in:lolos,damaged,abnormal,migrate',
                'new_category_product' => 'nullable',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount' => 'nullable|numeric',
                'display_price' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $currentQuality = json_decode($migrateProduct->new_quality, true);
            $migrateReason = $currentQuality['migrate'] ?? null;

            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');

            $qualityData = [
                'lolos' => $status === 'lolos' ? 'lolos' : null,
                'damaged' => $status === 'damaged' ? $description : null,
                'abnormal' => $status === 'abnormal' ? $description : null,
                'migrate' => $migrateReason
            ];

            $inputData = $request->only([
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_category_product',
                'new_tag_product',
                'new_discount',
            ]);

            $inputData['new_status_product'] = 'migrate';
            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();

            if ($inputData['old_price_product'] >= 100000) {
                $inputData['new_tag_product'] = null;
                if (empty($inputData['new_category_product'])) {
                    return (new ResponseResource(false, "Kategori produk wajib diisi untuk harga di atas 100k.", null))->response()->setStatusCode(422);
                }
                $category = Category::where('name_category', $inputData['new_category_product'])->first();
                if (!$category) {
                    return (new ResponseResource(false, "Kategori tidak ditemukan.", null))->response()->setStatusCode(422);
                }

                if (isset($category->discount_category) && $category->discount_category > 0) {
                    $discountAmount = ($category->discount_category / 100) * $inputData['old_price_product'];
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;
                    if (round($calculatedPrice) != round($inputData['new_price_product'])) {
                        return (new ResponseResource(false, "Harga setelah diskon kategori tidak sesuai.", null))->response()->setStatusCode(422);
                    }
                }
            }

            $inputData['new_quality'] = json_encode($qualityData);

            $manualDiscount = $request->input('new_discount', 0);
            if ($manualDiscount > 0) {
                $inputData['new_discount'] = $manualDiscount;
                $inputData['display_price'] = $inputData['new_price_product'] - $manualDiscount;
            } else {
                $inputData['new_discount'] = 0;
                $inputData['display_price'] = $inputData['new_price_product'];
            }

            $original_barcode = $migrateProduct->new_barcode_product;
            $original_new_price = $migrateProduct->new_price_product;
            $original_old_price = $migrateProduct->old_price_product;

            $migrateProduct->update($inputData);
            $migrateProduct->refresh();

            if (function_exists('logUserAction')) {
                logUserAction(
                    $request,
                    $user,
                    "migrate-bulky/product/update",
                    "Update -> Barcode: {$inputData['new_barcode_product']}, Price: {$inputData['new_price_product']}, Display: {$inputData['display_price']}. Old: {$original_new_price}"
                );
            }

            DB::commit();
            return new ResponseResource(true, "Produk Migrate Bulky Berhasil di Update", $migrateProduct);
        } catch (Exception $e) {
            DB::rollback();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function destroy(MigrateBulkyProduct $migrateBulkyProduct)
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {
            $migrateBulky = MigrateBulky::where('user_id', $user->id)
                ->where('status_bulky', 'proses')
                ->first();

            if (!$migrateBulky) {
                return response()->json(['errors' => ['migrate_bulky' => ['Tidak ada sesi migrasi yang aktif!']]], 422);
            }

            $resetQuality = json_encode(['lolos' => 'lolos', 'damaged' => null, 'abnormal' => null, 'non' => null, 'migrate' => null]);
            $model = $migrateBulkyProduct->new_product_id;

            $originProduct = StagingProduct::where('id', $model)
                ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)->first();

            if (!$originProduct) {
                $originProduct = New_product::where('id', $model)
                    ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)->first();
            }

            if ($originProduct) {
                $originProduct->update([
                    'new_status_product' => 'display',
                    'new_quality' => $resetQuality,
                ]);
            }

            $migrateBulkyProduct->delete();

            DB::commit();

            $migrateBulky->load(['migrateBulkyProducts' => function ($query) {
                $query->where('new_status_product', '!=', 'dump');
            }]);

            return new ResponseResource(true, "Data berhasil dihapus dan dikembalikan ke list asal!", $migrateBulky);
        } catch (Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Data gagal dihapus! " . $e->getMessage(), []);
        }
    }

    public function toDisplay(Request $request, $id)
    {
        $user = auth()->user();

        DB::beginTransaction();
        try {
            $migrateProduct = MigrateBulkyProduct::find($id);

            if (!$migrateProduct) {
                return (new ResponseResource(false, "Produk Migrate Bulky tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            $validator = Validator::make($request->all(), [
                'code_document' => 'required',
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'condition' => 'required|in:lolos,damaged,abnormal,migrate',
                'new_category_product' => 'nullable|exists:categories,name_category',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $inputData = $request->only([
                'code_document', 'old_barcode_product', 'new_barcode_product',
                'new_name_product', 'new_quantity_product', 'new_price_product',
                'old_price_product', 'new_category_product', 'new_tag_product',
                'new_discount'
            ]);

            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
            $inputData['new_status_product'] = 'display';
            $inputData['new_quality'] = json_encode(['lolos' => 'lolos']);
            $inputData['user_id'] = $user->id;

            $manualDiscount = $request->input('new_discount', 0);
            if ($manualDiscount > 0) {
                $inputData['new_discount'] = $manualDiscount;
                $inputData['display_price'] = $inputData['new_price_product'] - $manualDiscount;
            } else {
                $inputData['new_discount'] = 0;
                $inputData['display_price'] = $inputData['new_price_product'];
            }

            if ($inputData['old_price_product'] >= 100000) {
                $inputData['new_tag_product'] = null;
                if (empty($inputData['new_category_product'])) {
                    return (new ResponseResource(false, "Kategori produk wajib diisi untuk harga di atas 100k.", null))->response()->setStatusCode(422);
                }
            } else {
                $inputData['new_category_product'] = null;
            }

            $targetBarcode = $inputData['new_barcode_product'];
            $existingProduct = null;

            $existingDisplay = New_product::where('new_barcode_product', $targetBarcode)->first();

            if ($existingDisplay) {
                $existingDisplay->update($inputData);
                $existingProduct = $existingDisplay;
                logUserAction($request, $user, "migrate-bulky/to-display", "Updated existing Display Product: $targetBarcode");
            } else {
                $existingStaging = StagingProduct::where('new_barcode_product', $targetBarcode)->first();

                if ($existingStaging) {
                    $existingStaging->delete();
                    $existingProduct = New_product::create($inputData);
                    logUserAction($request, $user, "migrate-bulky/to-display", "Moved Staging to Display: $targetBarcode");
                } else {
                    $existingProduct = New_product::create($inputData);
                    logUserAction($request, $user, "migrate-bulky/to-display", "Created Fresh Display Product: $targetBarcode");
                }
            }

            $migrateProduct->delete();

            DB::commit();

            return new ResponseResource(true, "Produk berhasil dipindahkan ke Inventory Display", $existingProduct);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }
}