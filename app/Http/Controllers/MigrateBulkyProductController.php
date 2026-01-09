<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ApproveQueue;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\MigrateBulky;
use App\Models\MigrateBulkyProduct;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\Rack;
use App\Models\StagingProduct;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MigrateBulkyProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        $migrateBulky = MigrateBulky::where('user_id', $user->id)->where('status_bulky', 'proses')->first();
        return new ResponseResource(true, "List data persiapan produk migrate!", $migrateBulky->load('migrateBulkyProducts'));
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

        $user = Auth::user();
        $source = $request->source;
        $productId = $request->product_id;

        DB::beginTransaction();
        try {

            $product = null;
            if ($source === 'staging') {
                $product = StagingProduct::find($productId);
            } else {
                $product = New_product::find($productId);
            }

            if (!$product) {
                return response()->json(['errors' => ['product_id' => ['Produk tidak ditemukan di ' . $source]]], 404);
            }


            $migrateBulky = MigrateBulky::where('user_id', $user->id)
                ->where('status_bulky', 'proses')
                ->first();

            if (!$migrateBulky) {
                $lastCode = MigrateBulky::whereDate('created_at', today())
                    ->max(DB::raw("CAST(SUBSTRING_INDEX(code_document, '/', 1) AS UNSIGNED)"));
                $newCode = str_pad(($lastCode + 1), 4, '0', STR_PAD_LEFT);
                $codeDocument = sprintf('%s/%s/%s', $newCode, date('m'), date('d'));

                $migrateBulky = MigrateBulky::create([
                    'user_id' => $user->id,
                    'name_user' => $user->name,
                    'code_document' => $codeDocument,
                    'status_bulky' => 'proses',
                    'total_product' => 0,
                    'total_price' => 0,
                ]);
            }


            $isDuplicate = MigrateBulkyProduct::where('migrate_bulky_id', $migrateBulky->id)
                ->where('new_barcode_product', $product->new_barcode_product)
                ->exists();

            if ($isDuplicate) {
                return response()->json(['errors' => ['product_id' => ['Produk ini sudah ada di list migrasi Anda!']]], 422);
            }

            $previousRackId = $product->rack_id;

            $qualityData = [
                'lolos' => null,
                'damaged' => null,
                'abnormal' => null,
                'migrate' => $request->description
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

            MigrateBulkyProduct::create($productData);

            $product->update([
                'new_status_product' => 'migrate',
                'new_quality' => $jsonMigrate,
                'rack_id' => null
            ]);


            if ($previousRackId) {
                $rack = Rack::find($previousRackId);

                if ($rack) {
                    if ($source === 'staging') {
                        $productsInRack = $rack->stagingProducts();
                    } else {
                        $productsInRack = $rack->newProducts();
                    }

                    $rack->update([
                        'total_data' => $productsInRack->count(),
                        'total_new_price_product' => $productsInRack->sum('new_price_product'),
                        'total_old_price_product' => $productsInRack->sum('old_price_product'),
                        'total_display_price_product' => $productsInRack->sum('display_price'),
                    ]);
                }
            }

            DB::commit();

            $migrateBulky->load(['migrateBulkyProducts' => function ($query) {
                $query->where('new_status_product', '!=', 'dump');
            }]);

            if ($migrateBulky->migrateBulkyProducts) {
                $migrateBulky->migrateBulkyProducts->transform(function ($item) {
                    $item->source = 'migrate';
                    return $item;
                });
            }

            return new ResponseResource(true, "Data berhasil disimpan!", $migrateBulky);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Data gagal disimpan! " . $e->getMessage(), []);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $product = \App\Models\MigrateBulkyProduct::find($id);

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

    /**
     * Update the specified resource in storage.
     */
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
                'display_price',
            ]);

            $inputData['new_status_product'] = 'migrate';

            $indonesiaTime = \Carbon\Carbon::now('Asia/Jakarta');
            $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

            // --- Logika Harga (Harga > 100k dan < 100k) ---
            if ($inputData['old_price_product'] >= 100000) {
                $inputData['new_tag_product'] = null;

                if (empty($inputData['new_category_product'])) {
                    return (new ResponseResource(false, "Kategori produk wajib diisi untuk harga di atas 100k.", null))
                        ->response()->setStatusCode(422);
                }

                $category = Category::where('name_category', $inputData['new_category_product'])->first();
                if (!$category) {
                    return (new ResponseResource(false, "Kategori '" . $inputData['new_category_product'] . "' tidak ditemukan.", null))
                        ->response()->setStatusCode(422);
                }

                if (isset($category->discount_category) && $category->discount_category > 0) {
                    $discountAmount = ($category->discount_category / 100) * $inputData['old_price_product'];
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;
                    $inputPrice = $inputData['new_price_product'];

                    if (round($calculatedPrice) != round($inputPrice)) {
                        $errorMsg = "Harga setelah diskon kategori tidak sesuai. Harap periksa kembali.";
                        return (new ResponseResource(false, $errorMsg, null))
                            ->response()->setStatusCode(422);
                    }
                }
            }

            $inputData['new_quality'] = json_encode($qualityData);
            $inputData['display_price'] = $inputData['new_price_product'];

            $original_barcode = $migrateProduct->new_barcode_product;
            $original_new_price = $migrateProduct->new_price_product;
            $original_old_price = $migrateProduct->old_price_product;

            // Eksekusi Update
            $migrateProduct->update($inputData);

            // Log Action
            logUserAction(
                $request,
                $user,
                "migrate-bulky/product/update",
                "Direct Update -> Barcode: " . $inputData['new_barcode_product'] .
                    ", New Price: " . $inputData['new_price_product'] .
                    ", Old Price: " . $inputData['old_price_product'] .
                    ". Data Before Edit ->" .
                    " Before Edit Barcode: " . $original_barcode .
                    ", Before Edit New Price: " . $original_new_price .
                    ", Before Edit Old Price: " . $original_old_price
            );

            DB::commit();
            return new ResponseResource(true, "Produk Migrate Bulky Berhasil di Update", $migrateProduct);
        } catch (Exception $e) {
            DB::rollback();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()
                ->setStatusCode(500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
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

            $resetQuality = json_encode([
                'lolos' => 'lolos',
                'damaged' => null,
                'abnormal' => null,
                'non' => null,
                'migrate' => null,
            ]);

            $stagingProduct = StagingProduct::where('id', $migrateBulkyProduct->new_product_id)
                ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)
                ->first();

            if ($stagingProduct) {
                $stagingProduct->update(['new_status_product' => 'display', 'new_quality' => $resetQuality]);
            } else {
                $newProduct = New_product::where('id', $migrateBulkyProduct->new_product_id)
                    ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)
                    ->first();

                if ($newProduct) {
                    $newProduct->update([
                        'new_status_product' => 'display',
                        'new_quality' => $resetQuality
                    ]);
                }
            }

            $migrateBulkyProduct->delete();

            DB::commit();

            $migrateBulky->load(['migrateBulkyProducts' => function ($query) {
                $query->where('new_status_product', '!=', 'dump');
            }]);

            if ($migrateBulky->migrateBulkyProducts) {
                $migrateBulky->migrateBulkyProducts->transform(function ($product) {
                    $product->source = 'migrate';
                    return $product;
                });
            }

            return new ResponseResource(true, "Data berhasil dihapus dan dikembalikan ke list asal!", $migrateBulky);
        } catch (Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Data gagal dihapus! " . $e->getMessage(), []);
        }
    }

    public function toDisplay(Request $request, $id)
    {
        $user_id = auth()->id();

        try {
            DB::beginTransaction();

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
                'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
                'condition' => 'required|in:lolos,damaged,abnormal,migrate',
                'new_category_product' => 'nullable|exists:categories,name_category',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount' => 'nullable|numeric',
                'display_price' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

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
                'new_discount',
                'display_price',
            ]);

            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();

            if ($inputData['old_price_product'] >= 100000) {
                $inputData['new_tag_product'] = null;

                if (empty($inputData['new_category_product'])) {
                    return (new ResponseResource(false, "Kategori produk wajib diisi untuk harga di atas 100k.", null))
                        ->response()->setStatusCode(422);
                }

                $category = Category::where('name_category', $inputData['new_category_product'])->first();
                if (!$category) {
                    return (new ResponseResource(false, "Kategori '" . $inputData['new_category_product'] . "' tidak ditemukan.", null))
                        ->response()->setStatusCode(422);
                }

                if (isset($category->discount_category) && $category->discount_category > 0) {
                    $discountAmount = ($category->discount_category / 100) * $inputData['old_price_product'];
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;

                    $calculatedPriceFinal = round($calculatedPrice);
                    $inputPrice = round($inputData['new_price_product']);

                    if ($calculatedPriceFinal != $inputPrice) {
                        $errorMsg = "Harga setelah diskon kategori tidak sesuai. Sistem hitung: " . $calculatedPriceFinal . ". Harap periksa kembali.";
                        return (new ResponseResource(false, $errorMsg, null))
                            ->response()->setStatusCode(422);
                    }
                }
            } else {
                $inputData['new_category_product'] = null;

                $colortag = Color_tag::where('min_price_color', '<=', $inputData['old_price_product'])
                    ->where('max_price_color', '>=', $inputData['old_price_product'])
                    ->select('fixed_price_color', 'name_color')
                    ->first();

                if ($colortag) {
                    $inputData['new_price_product'] = $colortag['fixed_price_color'];
                    $inputData['new_tag_product'] = $colortag['name_color'];
                } else {
                    $inputData['new_tag_product'] = null;
                }
            }

            $inputData['new_status_product'] = 'display';
            $inputData['new_quality'] = json_encode(['lolos' => 'lolos']);
            $inputData['display_price'] = $inputData['new_price_product'];
            $inputData['user_id'] = auth()->id();

            $newProduct = New_product::create($inputData);

            $migrateProduct->delete();

            logUserAction(
                $request,
                $request->user(),
                "migrate-bulky/moved-to-display",
                "Moved Product to Display (New Product Table). Barcode: " . $inputData['new_barcode_product']
            );

            DB::commit();

            return new ResponseResource(true, "Produk berhasil dipindahkan ke Inventory Display (New Product)", $newProduct);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function listMigrateProducts(Request $request)
    {
        $query = $request->input('q');

        try {

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
                ->where('new_category_product', 'LIKE', 'ELEKTRONIK%')
                ->whereNotNull('new_category_product')
                ->where('new_tag_product', NULL)
                ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
                })->where(function ($type) {
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
                })->where(function ($type) {
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

    public function storeByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode'     => 'required|string',
            'description' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $barcode = $request->barcode;

        DB::beginTransaction();
        try {

            $product = null;
            $source = '';

            // 1. Cari Produk di Staging
            $product = StagingProduct::where('new_barcode_product', $barcode)
                ->orWhere('old_barcode_product', $barcode)
                ->first();

            if ($product) {
                $source = 'staging';
            } else {
                // 2. Cari Produk di Display (New Product)
                $product = New_product::where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode)
                    ->first();

                if ($product) {
                    $source = 'display';
                }
            }

            if (!$product) {
                return response()->json(['errors' => ['barcode' => ['Produk tidak ditemukan dengan barcode tersebut.']]], 404);
            }

            if (stripos($product->new_category_product, 'ELEKTRONIK') === false) {
                return response()->json([
                    'errors' => [
                        'barcode' => [
                            'Scan Gagal! Produk ini kategori "' . $product->new_category_product . '". Hanya kategori ELEKTRONIK yang diperbolehkan.'
                        ]
                    ]
                ], 422);
            }

            $migrateBulky = MigrateBulky::where('user_id', $user->id)
                ->where('status_bulky', 'proses')
                ->first();

            if (!$migrateBulky) {
                $lastCode = MigrateBulky::whereDate('created_at', today())
                    ->max(DB::raw("CAST(SUBSTRING_INDEX(code_document, '/', 1) AS UNSIGNED)"));
                $newCode = str_pad(($lastCode + 1), 4, '0', STR_PAD_LEFT);
                $codeDocument = sprintf('%s/%s/%s', $newCode, date('m'), date('d'));

                $migrateBulky = MigrateBulky::create([
                    'user_id' => $user->id,
                    'name_user' => $user->name,
                    'code_document' => $codeDocument,
                    'status_bulky' => 'proses',
                    'total_product' => 0,
                    'total_price' => 0,
                ]);
            }

            $isDuplicate = MigrateBulkyProduct::where('migrate_bulky_id', $migrateBulky->id)
                ->where('new_barcode_product', $product->new_barcode_product)
                ->exists();

            if ($isDuplicate) {
                return response()->json(['errors' => ['barcode' => ['Produk ini sudah ada di list migrasi Anda!']]], 422);
            }

            $previousRackId = $product->rack_id;
            $qualityData = [
                'lolos' => null,
                'damaged' => null,
                'abnormal' => null,
                'migrate' => $request->description
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

            MigrateBulkyProduct::create($productData);

            $product->update([
                'new_status_product' => 'migrate',
                'new_quality' => $jsonMigrate,
                'rack_id' => null
            ]);

            if ($previousRackId) {
                $rack = \App\Models\Rack::find($previousRackId);

                if ($rack) {
                    if ($source === 'staging') {
                        $productsInRack = $rack->stagingProducts();
                    } else {
                        $productsInRack = $rack->newProducts();
                    }

                    $rack->update([
                        'total_data' => $productsInRack->count(),
                        'total_new_price_product' => $productsInRack->sum('new_price_product'),
                        'total_old_price_product' => $productsInRack->sum('old_price_product'),
                        'total_display_price_product' => $productsInRack->sum('display_price'),
                    ]);
                }
            }

            DB::commit();

            $migrateBulky->load(['migrateBulkyProducts' => function ($query) {
                $query->where('new_status_product', '!=', 'dump');
            }]);

            if ($migrateBulky->migrateBulkyProducts) {
                $migrateBulky->migrateBulkyProducts->transform(function ($item) {
                    $item->source = 'migrate';
                    return $item;
                });
            }

            return new ResponseResource(true, "Produk berhasil ditambahkan via Scan Barcode!", $migrateBulky);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal memproses barcode! " . $e->getMessage(), []);
        }
    }
}
