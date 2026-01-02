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

    /**
     * Store a newly created resource in storage.
     */
    public function store(New_product $new_product)
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {
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
                ->where('new_barcode_product', $new_product->new_barcode_product)
                ->exists();

            if ($isDuplicate) {
                return response()->json(['errors' => ['new_product_id' => ['Produk ini sudah ada di list migrasi Anda!']]], 422);
            }

            $productData = $new_product->toArray();
            unset($productData['created_at'], $productData['updated_at']);

            $productData['migrate_bulky_id'] = $migrateBulky->id;
            $productData['new_product_id'] = $new_product->id;
            $productData['user_id'] = $user->id;
            $productData['new_status_product'] = 'migrate';

            $productData['code_document'] = $migrateBulky->code_document;

            MigrateBulkyProduct::create($productData);

            $new_product->update(['new_status_product' => 'migrate']);

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
            $checkApproveQueue = ApproveQueue::where('type', 'migrate_bulky')
                ->where('product_id', $migrateProduct->id)
                ->where('status', '1')
                ->first();

            if ($checkApproveQueue) {
                return (new ResponseResource(false, "Product sudah ada dalam antrian approve SPV, konfirmasi ke SPV", null))
                    ->response()->setStatusCode(422);
            }

            $user = auth()->user()->email;

            $validator = Validator::make($request->all(), [
                'code_document' => 'required',
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
                'condition' => 'required|in:lolos,damaged,abnormal',
                'new_category_product' => 'nullable',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount' => 'nullable|numeric',
                'display_price' => 'nullable|numeric',
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
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'new_discount',
                'display_price',
            ]);

            $indonesiaTime = \Carbon\Carbon::now('Asia/Jakarta');
            $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

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

            if ($status !== 'lolos') {
                $inputData['new_price_product'] = null;
                $inputData['new_category_product'] = null;
            }

            $inputData['new_quality'] = json_encode($qualityData);
            $inputData['display_price'] = $inputData['new_price_product'];

            $userRole = User::with('role')->where('id', auth()->id())->first();

            $original_barcode = $migrateProduct->new_barcode_product;
            $original_new_price = $migrateProduct->new_price_product;
            $original_old_price = $migrateProduct->old_price_product;

            if ($userRole->role->role_name != 'Admin' && $userRole->role->role_name != 'Spv') {
                ApproveQueue::create([
                    'user_id' => auth()->id(),
                    'product_id' => $migrateProduct->id,
                    'type' => 'migrate_bulky',
                    'code_document' => $inputData['code_document'],
                    'old_price_product' => $inputData['old_price_product'],
                    'new_name_product' => $inputData['new_name_product'],
                    'new_quantity_product' => $inputData['new_quantity_product'],
                    'new_price_product' => $inputData['new_price_product'],
                    'new_discount' => $inputData['new_discount'],
                    'new_tag_product' => $inputData['new_tag_product'],
                    'new_category_product' => $inputData['new_category_product'],
                    'status' => '1'
                ]);

                $notification = Notification::create([
                    'user_id' => auth()->id(),
                    'notification_name' => "edit product migrate bulky " . $inputData['new_barcode_product'],
                    'role' => 'Spv',
                    'read_at' => \Carbon\Carbon::now('Asia/Jakarta'),
                    'riwayat_check_id' => null,
                    'repair_id' => null,
                    'status' => 'migrate_bulky',
                    'external_id' => $migrateProduct->id,
                    'approved' => '0'
                ]);

                logUserAction(
                    $request,
                    $request->user(),
                    "migrate-bulky/product/update",
                    "Barcode: " . $inputData['new_barcode_product'] .
                        ", New Price: " . $inputData['new_price_product'] .
                        ", Old Price: " . $inputData['old_price_product'] .
                        ". Data Before Edit" .
                        ", Before Edit Barcode: " . $original_barcode .
                        ", Before Edit New Price: " . $original_new_price .
                        ", Before Edit Old Price: " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );

                DB::commit();
                return new ResponseResource(true, "Permintaan update dikirim ke SPV", null);
            } else {
                $migrateProduct->update($inputData);

                logUserAction(
                    $request,
                    $request->user(),
                    "migrate-bulky/product/update",
                    "Barcode: " . $inputData['new_barcode_product'] .
                        ", New Price: " . $inputData['new_price_product'] .
                        ", Old Price: " . $inputData['old_price_product'] .
                        ". Data Before Edit ->" .
                        " Before Edit Barcode: " . $original_barcode .
                        ", Before Edit New Price: " . $original_new_price .
                        ", Before Edit Old Price: " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );

                DB::commit();
                return new ResponseResource(true, "Produk Migrate Bulky Berhasil di Update", $migrateProduct);
            }
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

            $stagingProduct = StagingProduct::where('id', $migrateBulkyProduct->new_product_id)
                ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)
                ->first();

            if ($stagingProduct) {
                $stagingProduct->update(['new_status_product' => 'display']); 
            } 
            else {
                $newProduct = New_product::where('id', $migrateBulkyProduct->new_product_id)
                    ->where('new_barcode_product', $migrateBulkyProduct->new_barcode_product)
                    ->first();

                if ($newProduct) {
                    $newProduct->update(['new_status_product' => 'display']);
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
                'condition' => 'required|in:lolos,damaged,abnormal',
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
}
