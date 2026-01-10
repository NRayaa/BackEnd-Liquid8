<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\Document;
use App\Models\ExcelOld;
use App\Models\BundleQcd;
use App\Models\Color_tag;
use App\Models\Destination;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\ApproveQueue;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\FilterStaging;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use App\Exports\ProductByColor;
use App\Models\MigrateBulkyProduct;
use App\Models\SummarySoCategory;
use Illuminate\Support\Facades\DB;
use App\Exports\ProductExpiredSLMP;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ProductDamagedExport;
use App\Exports\ProductAbnormalExport;
use App\Exports\ProductInventoryCtgry;
use App\Exports\ProductExportMasSugeng;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Exports\ProductCategoryAndColorNull;
use App\Exports\ProductNonExport;
use App\Exports\TemplateBulkingCategory;
use App\Models\Rack;
use App\Models\SoColor;
use App\Models\SummarySoColor;

class NewProductController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');
        $newProducts = New_product::latest()->where(function ($queryBuilder) use ($query) {
            $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
        })->where('new_status_product', '!=', 'dump')
            ->where('new_status_product', '!=', 'scrap_qcd')
            ->where('new_status_product', '!=', 'expired')
            ->where('new_status_product', '!=', 'sale')
            ->where('new_status_product', '!=', 'migrate')
            ->where('new_status_product', '!=', 'repair')
            ->paginate(100);

        // $startNumber = ($newProducts->currentPage() - 1) * $newProducts->perPage() + 1 ;

        // $newProducts->getCollection()->transform(function($product) use (&$startNumber){
        //     $product->number = $startNumber++;
        //     return $product;
        // });

        return new ResponseResource(true, "list new product", $newProducts);
    }

    public function byDocument(Request $request)
    {
        $query = $request->input('code_document');

        $newProducts = New_product::where('code_document', $query)->paginate(100);

        if ($newProducts->isEmpty()) {
            return new ResponseResource(false, "No data found", null);
        }

        return new ResponseResource(true, "List new products", $newProducts);
    }

    public function create() {}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'old_barcode_product' => 'nullable',
            'new_barcode_product' => 'required|unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            // 'new_date_in_product' => 'required|date',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color'
        ],  [
            'new_barcode_product.unique' => 'barcode sudah ada',

        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // Logika untuk memproses data
            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');

            $qualityData = $this->prepareQualityData($status, $description);

            $inputData = $this->prepareInputData($request, $status, $qualityData);


            $newProduct = New_product::create($inputData);


            $this->updateDocumentStatus($request->input('code_document'));

            $this->deleteOldProduct($request->input('old_barcode_product'));

            DB::commit();

            $this->updateDocumentStatus($request->input('code_document'));

            $this->deleteOldProduct($request->input('old_barcode_product'));

            DB::commit();

            return new ResponseResource(true, "New Produk Berhasil ditambah", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function prepareQualityData($status, $description)
    {
        return [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null
        ];
    }

    private function prepareInputData($request, $status, $qualityData)
    {
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
            'type'
        ]);

        $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
        $inputData['new_quality'] = json_encode($qualityData);
        $inputData['type'] = 'type1';

        if ($status !== 'lolos') {
            $inputData['new_category_product'] = null;
            $inputData['new_price_product'] = null;
        }

        return $inputData;
    }

    private function updateDocumentStatus($codeDocument)
    {
        $document = Document::where('code_document', $codeDocument)->firstOrFail();
        if ($document->status_document === 'pending') {
            $document->update(['status_document' => 'in progress']);
        }
    }

    private function deleteOldProduct($old_barcode_product)
    {

        $oldProduct = Product_old::where('old_barcode_product', $old_barcode_product)->first();

        if ($oldProduct) {
            $oldProduct->delete();
        } else {

            return new ResponseResource(false, "Produk lama dengan barcode tidak ditemukan.", $oldProduct);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(New_product $new_product)
    {
        $category = Category::where('name_category', $new_product['new_category_product'])->first();
        $new_product['discount_category'] = $category ? $category->discount_category : null;
        $approveQueue = ApproveQueue::where('product_id', $new_product->id)->where('status', '1')->first();
        if ($approveQueue) {
            $new_product['status'] = 'not_editable';
        } else {
            $new_product['status'] = 'editable';
        }
        return new ResponseResource(true, "data new product", $new_product);
    }

    public function showProductByBarcode($barcode)
    {
        $product = New_product::where('new_barcode_product', $barcode)->first();
        $source = 'display';

        if (!$product) {
            $product = StagingProduct::where('new_barcode_product', $barcode)->first();
            $source = 'staging';
        }

        if (!$product) {
            return (new ResponseResource(false, "Data produk dengan barcode '$barcode' tidak ditemukan!", null))
                ->response()
                ->setStatusCode(404);
        }

        $category = Category::where('name_category', $product->new_category_product)->first();
        $product['discount_category'] = $category ? $category->discount_category : null;

        if ($source === 'new_product') {
            $approveQueue = ApproveQueue::where('product_id', $product->id)
                ->where('status', '1')
                ->first();

            if ($approveQueue) {
                $product['status'] = 'not_editable';
            } else {
                $product['status'] = 'editable';
            }
        } else {
            $product['status'] = 'editable';
        }

        $product['source'] = $source;

        return new ResponseResource(true, "Detail data produk", $product);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(New_product $new_product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, New_product $new_product)
    {
        DB::beginTransaction();
        try {
            $checkApproveQueue = ApproveQueue::where('type', 'inventory')->where('product_id', $new_product->id)->where('status', '1')->first();
            if ($checkApproveQueue) {
                return (new ResponseResource(false, "product sudah ada dalam antrian approve spv, konfirmasi ke spv", null))
                    ->response()->setStatusCode(422);
            }

            $user = auth()->user()->email;
            $validator = Validator::make($request->all(), [
                'code_document' => 'nullable',
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
                'condition' => 'nullable',
                'new_category_product' => 'nullable',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount' => 'nullable|numeric',
                'display_price' => 'required|numeric'
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
                'new_date_in_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'new_discount',
                'display_price'
            ]);

            $indonesiaTime = Carbon::now('Asia/Jakarta');
            $inputData['new_date_in_product'] = $indonesiaTime->toDateString();
            // $inputData['display_price'] = $inputData['new_price_product'];


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
                    // if (isset($category->max_price_category) && $category->max_price_category > 0) {
                    //     if ($discountAmount > $category->max_price_category) {
                    //         $discountAmount = $category->max_price_category;
                    //     }
                    // }
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;
                    $calculatedPriceFinal = round($calculatedPrice);
                    $inputPrice = $inputData['new_price_product'];

                    if (round($calculatedPriceFinal) != round($inputPrice)) {
                        $errorMsg = "Harga tidak sesuai kalkulasi sistem (Diskon & Max Price Limit). Seharusnya: " . round($calculatedPriceFinal);

                        return (new ResponseResource(false, $errorMsg, null))
                            ->response()->setStatusCode(422);
                    }
                }
            }

            if ($request->input('old_price_product') < 100000) {
                $tagwarna = Color_tag::where('min_price_color', '<=', $request->input('old_price_product'))
                    ->where('max_price_color', '>=', $request->input('old_price_product'))
                    ->select('fixed_price_color', 'name_color')->first();
                $inputData['new_tag_product'] = $tagwarna['name_color'];
                $inputData['new_price_product'] = $tagwarna['fixed_price_color'];
                $inputData['new_category_product'] = null;
            }

            if ($status !== 'lolos') {
                // Set nilai-nilai default jika status bukan 'lolos'
                $inputData['new_price_product'] = null;
                $inputData['new_category_product'] = null;
            }

            $inputData['new_quality'] = json_encode($qualityData);

            if ($new_product->new_category_product != null) {
                $inputData['new_barcode_product'] = $new_product->new_barcode_product;
            }
            $userRole = User::where('id', auth()->id())->first();

            $original_barcode = $new_product->new_barcode_product;
            $original_new_price = $new_product->new_price_product;
            $original_old_price = $new_product->old_price_product;

            if ($userRole->role->role_name != 'Admin' && $userRole->role->role_name != 'Spv') {
                $response =  ApproveQueue::create([
                    'user_id' => auth()->id(),
                    'product_id' => $new_product->id,
                    'type' => 'inventory',
                    'code_document' => $inputData['code_document'],
                    'old_price_product' => $inputData['old_price_product'],
                    'new_name_product' => $inputData['new_name_product'],
                    'new_quantity_product' => $inputData['new_quantity_product'],
                    'new_price_product' => $inputData['new_price_product'],
                    'new_discount' => $inputData['new_discount'],
                    'new_tag_product' => $inputData['new_tag_product'],
                    'new_category_product' => $inputData['new_category_product'],
                    'status' => '1',
                ]);

                //perubahan alur
                // $new_product->update($inputData);
                $notification = Notification::create([
                    'user_id' => auth()->id(),
                    'notification_name' => "edit product inventory" . " " . $inputData['new_barcode_product'],
                    'role' => 'Spv',
                    'read_at' => Carbon::now('Asia/Jakarta'),
                    'riwayat_check_id' => null,
                    'repair_id' => null,
                    'status' => 'inventory',
                    'external_id' => $new_product->id,
                    'approved' => '0'
                ]);

                logUserAction(
                    $request,
                    $request->user(),
                    "Inventory/product/category/detail",
                    "barcode " . $inputData['new_barcode_product'] .
                        ", new_price " . $inputData['new_price_product'] .
                        ", old_price " . $inputData['old_price_product'] .
                        ". before_edit_barcode " . $original_barcode .
                        ", before_edit_new_price " . $original_new_price .
                        ", before_edit_old_price " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );
            } else {
                $response = $new_product->update($inputData);
                $new_product->save();
                logUserAction(
                    $request,
                    $request->user(),
                    "Inventory/product/category/detail",
                    "barcode " . $inputData['new_barcode_product'] .
                        ", new_price " . $inputData['new_price_product'] .
                        ", old_price " . $inputData['old_price_product'] .
                        ". Data Before Edit ->" .
                        ". before_edit_barcode " . $original_barcode .
                        ", before_edit_new_price " . $original_new_price .
                        ", before_edit_old_price " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );
            }

            DB::commit();
            return new ResponseResource(true, "New Produk Berhasil di Update", $response);
        } catch (\Exception $e) {
            DB::rollback();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()
                ->setStatusCode(500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id, Request $request)
    {
        $user = auth()->user()->email;
        $product = null;
        $source = '';

        if ($request->has('source')) {
            if ($request->source == 'staging') {
                $product = StagingProduct::find($id);
                $source = 'staging';
            } elseif ($request->source == 'display') {
                $product = New_product::find($id);
                $source = 'display';
            }
        } else {
            $product = StagingProduct::find($id);
            $source = 'staging';

            if (!$product) {
                $product = New_product::find($id);
                $source = 'display';
            }
        }

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->update([
            'new_status_product' => 'dump'
        ]);

        logUserAction(
            $request,
            $request->user(),
            "storage/product/category",
            "barcode " . $product->new_barcode_product . " ($source) memindahkan product ke dump ->" . $user
        );

        return new ResponseResource(true, "Produk ($source) berhasil dipindahkan ke status Dump", $product);
    }

    public function deleteAll()
    {
        try {
            // ListProductBP::query()->delete();
            New_product::query()->delete();
            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return response()->json(["error" => $e], 402);
        }
    }

    public function updateToDamaged(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer',
            'source'     => 'required|in:staging,display',
            'description' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user_id = auth()->id();
        $source = $request->source;
        $id = $request->product_id;
        $description = $request->description;

        DB::beginTransaction();
        try {
            $product = null;
            if ($source === 'staging') {
                $product = StagingProduct::find($id);
            } else {
                $product = New_product::find($id);
            }

            if (!$product) {
                return new ResponseResource(false, "Produk tidak ditemukan di " . ucfirst($source), null);
            }

            $currentQuality = json_decode($product->new_quality, true);

            if (!isset($currentQuality['lolos']) || $currentQuality['lolos'] !== 'lolos') {
                return new ResponseResource(false, "Gagal: Produk ini statusnya bukan 'Lolos' (Mungkin sudah damaged/abnormal)", null);
            }

            $previousRackId = $product->rack_id;
            $sourceType = $source;

            $newQuality = [
                'lolos' => null,
                'damaged' => $description,
                'abnormal' => null
            ];

            // Simpan perubahan ke database
            $product->new_quality = json_encode($newQuality);
            $product->rack_id = null;

            // Jika ingin mencatat history quality asli sebelum rusak
            // $product->actual_new_quality = json_encode($newQuality); 

            $product->save();

            if ($previousRackId) {
                $rack = Rack::find($previousRackId);
                if ($rack) {
                    // Logika hitung ulang (mirip recalculateRackTotals)
                    if ($sourceType === 'staging') {
                        $products = $rack->stagingProducts(); // Relasi di model Rack
                    } else {
                        $products = $rack->newProducts(); // Relasi di model Rack
                    }

                    $rack->update([
                        'total_data' => $products->count(),
                        'total_new_price_product' => $products->sum('new_price_product'),
                        'total_old_price_product' => $products->sum('old_price_product'),
                        'total_display_price_product' => $products->sum('display_price'),
                    ]);
                }
            }

            // Update Counter Stock Opname (SO) - Agar sinkron
            // Kurangi 'Inventory/Total' -> Tambah 'Damaged'

            $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            $checkSoColor = SummarySoColor::where('type', 'process')->first();

            // Update SO Category (Jika barang kategori)
            if ($checkSoCategory && $product->new_category_product) {
                // Kurangi stok ready
                if ($checkSoCategory->product_inventory > 0) {
                    $checkSoCategory->decrement('product_inventory');
                }
                // Tambah stok damaged
                $checkSoCategory->increment('product_damaged');
            }

            if ($checkSoColor && $product->new_tag_product) {
                $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                    ->where('color', $product->new_tag_product)
                    ->first();

                if ($soColor) {
                    if ($soColor->total_color > 0) {
                        $soColor->decrement('total_color');
                    }
                    $soColor->increment('product_damaged');
                }
            }

            logUserAction(
                $request,
                $request->user(),
                "Inventory/Damage",
                "Mengubah status product menjadi DAMAGED. Barcode: " . $product->new_barcode_product . ", Alasan: " . $description
            );

            DB::commit();

            return new ResponseResource(true, "Produk berhasil diubah statusnya menjadi Damaged", $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => "Terjadi kesalahan: " . $e->getMessage()], 500);
        }
    }

    public function expireProducts()
    {
        // $fourWeeksAgo = now()->subWeeks(4)->toDateString();
        $ninetyDaysAgo = now()->subDays(91)->toDateString();

        $products = New_product::where('new_date_in_product', '<=', $ninetyDaysAgo)
            ->where('new_status_product', 'display')
            ->get();

        foreach ($products as $product) {
            $product->update(['new_status_product' => 'expired']);
        }

        return new ResponseResource(true, "Products update to expired successfully", $products);
    }

    public function slowMovingProducts()
    {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();

        try {
            // $fourWeeksAgo = now()->subWeeks(4)->toDateString();
            $daysAgo = now()->subDays(60)->toDateString();

            $products = New_product::where('new_date_in_product', '<=', $daysAgo)
                ->where('new_status_product', 'display')
                ->get();

            foreach ($products as $product) {
                $product->update(['new_status_product' => 'slow_moving']);
            }
            Log::info("Cron job Berhasil di jalankan " . date('Y-m-d H:i:s'));
            return new ResponseResource(true, "Products update to slow_moving successfully", $products);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return (new ResponseResource(false, "Products slow_moving successfully", []))
                ->response()
                ->setStatusCode(500);
        }
    }


    public function listProductExp(Request $request)
    {
        try {
            $search = $request->input('q');
            $productExpired = New_product::where('new_status_product', 'expired')
                ->where(function ($queryBuilder) use ($search) {
                    $queryBuilder->where('new_name_product', 'LIKE', '%' . $search  . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $search . '%');
                })
                ->paginate(50);

            return new ResponseResource(true, "list product expired", $productExpired);
        } catch (\Exception $e) {
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }
    public function slowMov(Request $request)
    {
        try {
            $search = $request->input('q');
            $products = New_product::where('new_status_product', 'slow_moving')
                ->where(function ($queryBuilder) use ($search) {
                    $queryBuilder->where('new_name_product', 'LIKE', '%' . $search  . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $search . '%');
                })
                ->paginate(50);

            return new ResponseResource(true, "list product slow moving", $products);
        } catch (\Exception $e) {
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }


    public function listProductExpDisplay(Request $request)
    {
        try {
            $query = $request->input('q');

            $productExpDisplayQuery = New_product::latest()
                ->where(function ($queryBuilder) {
                    $queryBuilder->where('new_status_product', 'expired')
                        ->orWhere('new_status_product', 'display');
                })
                ->whereRaw("JSON_EXTRACT(new_quality, '$.lolos') IS NOT NULL");

            if (!empty($query)) {
                $productExpDisplayQuery->where(function ($subBuilder) use ($query) {
                    $subBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('code_document', 'LIKE', '%' . $query . '%');
                });
                $productExpDisplay = $productExpDisplayQuery->paginate(50);
            } else {
                $productExpDisplay = $productExpDisplayQuery->paginate(50);
            }

            // Mengembalikan hasil dalam response yang diinginkan
            return new ResponseResource(true, "List product expired/display", $productExpDisplay);
        } catch (\Exception $e) {
            // Tampilkan pesan error jika terjadi kesalahan
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }

    protected function generateDocumentCode()
    {
        $latestDocument = Document::latest()->first();
        $newId = $latestDocument ? $latestDocument->id + 1 : 1;
        $id_document = str_pad($newId, 4, '0', STR_PAD_LEFT);
        $month = date('m');
        $year = date('Y');
        return $id_document . '/' . $month . '/' . $year;
    }

    //baru inject product warna
    public function processExcelFilesTagColor(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');
        $user_id = auth()->id();

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ], [
            'file.required' => 'File harus diunggah.',
            'file.file' => 'File yang diunggah tidak valid.',
            'file.mimes' => 'File harus berupa file Excel dengan ekstensi .xlsx atau .xls.',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        DB::beginTransaction();

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $ekspedisiData = $sheet->toArray(null, true, true, true);

            // Ambil header dari file
            $headersFromFile = $ekspedisiData[1]; // baris pertama (index 1) adalah header

            // Header yang diharapkan
            $expectedHeaders = [
                'Waybill',
                'Isi Barang',
                'Qty',
                'Nilai Barang Satuan',
            ];

            // Periksa apakah header sesuai
            if (array_diff($expectedHeaders, $headersFromFile) || array_diff($headersFromFile, $expectedHeaders)) {
                $response = new ResponseResource(false, "header tidak sesuai, berikut header yang benar : ", $expectedHeaders);
                return $response->response()->setStatusCode(422);
            }

            $chunkSize = 100;
            $count = 0;
            $headerMappings = [
                'old_barcode_product' => 'Waybill',
                'new_name_product' => 'Isi Barang',
                'new_quantity_product' => 'Qty',
                'old_price_product' => 'Nilai Barang Satuan',
                'new_category_product' => null,
                'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                'new_discount' => 0,
                'display_price' => 'Nilai Barang Satuan',
            ];

            // Ensure unique code_document before starting the process
            $code_document = $this->generateDocumentCode();
            while (Document::where('code_document', $code_document)->exists()) {
                $code_document = $this->generateDocumentCode(); // Generate a new one if a duplicate is found
            }

            // Process in chunks
            for ($i = 1; $i < count($ekspedisiData); $i += $chunkSize) {
                $chunkData = array_slice($ekspedisiData, $i, $chunkSize);
                $newProductsToInsert = [];

                foreach ($chunkData as $dataItem) {
                    $newProductDataToInsert = [];
                    foreach ($headerMappings as $key => $headerName) {
                        $columnKey = array_search($headerName, $ekspedisiData[1]);
                        if ($columnKey !== false) {
                            $value = trim($dataItem[$columnKey]);
                            if ($key === 'new_quantity_product') {
                                $quantity = $value !== '' ? (int)$value : 0;
                                $newProductDataToInsert[$key] = $quantity;
                            } elseif ($key === 'old_price_product' || $key === 'display_price') {
                                $newProductDataToInsert[$key] = (float)str_replace(',', '', $value);
                            } else {
                                $newProductDataToInsert[$key] = $value;
                            }
                        }
                    }

                    // Skip jika old_price_product lebih dari 99.999
                    if (isset($newProductDataToInsert['old_price_product']) && $newProductDataToInsert['old_price_product'] > 99999) {
                        continue; // Lanjutkan ke item berikutnya jika harga di atas 99.999
                    }

                    // Proses untuk old_price_product kurang dari 100.000
                    if (isset($newProductDataToInsert['old_price_product']) && $newProductDataToInsert['old_price_product'] < 100000) {
                        $colors = Color_tag::where('min_price_color', '<=', $newProductDataToInsert['old_price_product'])
                            ->where('max_price_color', '>=', $newProductDataToInsert['old_price_product'])
                            ->first();

                        if ($colors) {
                            $newProductDataToInsert['new_tag_product'] = $colors->name_color;
                            $newProductDataToInsert['display_price'] = $colors->fixed_price_color;
                            $newProductDataToInsert['new_price_product'] = $colors->fixed_price_color;
                        }
                    }

                    $newProductDataToInsert = array_merge($newProductDataToInsert, [
                        'code_document' => $code_document,
                        'type' => 'type1',
                        'user_id' => $user_id,
                        'is_so' => null,
                        'new_tag_product' => $newProductDataToInsert['new_tag_product'] ?? null,
                        'new_quality' => json_encode(['lolos' => 'lolos']),
                        'actual_new_quality' => json_encode(['lolos' => 'lolos']),
                        'actual_old_price_product' => $newProductDataToInsert['old_price_product'] ?? 0,
                        'new_status_product' => 'display',
                        'new_barcode_product' => newBarcodeScan(),
                        'created_at' => Carbon::now('Asia/Jakarta')->toDateString(),
                    ]);

                    if (isset($newProductDataToInsert['old_barcode_product'], $newProductDataToInsert['new_name_product'])) {
                        $newProductsToInsert[] = $newProductDataToInsert;
                        $count++;
                    }
                }

                if (!empty($newProductsToInsert)) {
                    New_product::insert($newProductsToInsert);
                }
            }

            $getColor = New_product::where('code_document', $code_document)
                ->select('new_tag_product', DB::raw('COUNT(*) as total'))
                ->groupBy('new_tag_product')
                ->orderBy('new_tag_product')
                ->get();

            $checkSoColor = SummarySoColor::where('type', 'process')->first();
            if ($checkSoColor) {
                foreach ($getColor as $color) {
                    $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                        ->where('color', $color->new_tag_product)
                        ->first();
                    if ($soColor) {
                        $soColor->increment('total_color', $color->total);
                    }
                }
            }

            // Insert into the documents table after processing each chunk
            Document::create([
                'code_document' => $code_document,
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings),
                'total_column_in_document' => count($ekspedisiData) - 1, // Exclude header
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString()
            ]);
            $totalData = count($ekspedisiData) - 1;
            $totalDataIn = count($ekspedisiData) - 1;

            // Hitung total harga dari semua produk yang diproses
            $totalPrice = StagingProduct::where('code_document', $code_document)->sum('old_price_product');

            // Hitung persentase total data yang masuk
            $percentageTotalData = $totalData > 0 ? ($totalDataIn / $totalData) * 100 : 0;
            $history = RiwayatCheck::create([
                'user_id' => $user_id,
                'code_document' => $code_document,
                'base_document' => $fileName,
                'total_data' => $totalData,
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $totalDataIn,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'display',
                'precentage_total_data' => round($percentageTotalData, 2),
                'percentage_in' => round($percentageTotalData, 2),
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,
                'total_price' => $totalPrice,
                'value_data_lolos' => 0,
                'value_data_damaged' => 0,
                'value_data_abnormal' => 0,
                'value_data_discrepancy' => 0,
                'status_file' => true,
            ]);


            DB::commit();

            return new ResponseResource(true, "Data berhasil diproses dan disimpan", [
                'code_document' => $code_document,
                'file_name' => $fileName,
                'total_column_count' => count($headerMappings),
                'total_row_count' => count($ekspedisiData) - 2, // Exclude header
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }

    public function processExcelFilesCategory(Request $request)
    {
        $user_id = auth()->id();
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validate input file
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ], [
            'file.required' => 'File harus diunggah.',
            'file.file' => 'File yang diunggah tidak valid.',
            'file.mimes' => 'File harus berupa file Excel dengan ekstensi .xlsx atau .xls.',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        DB::beginTransaction();
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $ekspedisiData = $sheet->toArray(null, true, true, true);
            $chunkSize = 500;
            $count = 0;
            $headerMappings = [
                'old_barcode_product' => 'Barcode',
                'new_barcode_product' => 'Barcode',
                'new_name_product' => 'Description',
                'new_category_product' => 'Category',
                'new_quantity_product' => 'Qty',
                'new_price_product' => 'Price After Discount',
                'old_price_product' => 'Unit Price',
                'new_date_in_product' => 'Date',
                'display_price' => 'Price After Discount',
            ];

            $initBarcode = collect($ekspedisiData)->pluck('A');
            $duplicateInitBarcode = $initBarcode->duplicates();
            $barcodesOnly = $duplicateInitBarcode->values();

            if ($duplicateInitBarcode->isNotEmpty()) {
                $response = new ResponseResource(false, "barcode duplikat dari excel", $barcodesOnly);
                return $response->response()->setStatusCode(422);
            }

            $categoryAtExcel = collect($ekspedisiData)->pluck('C')->slice(1);
            $category = Category::latest()->pluck('name_category');
            $uniqueCategory = $categoryAtExcel->diff($category);
            $categoryOnly = $uniqueCategory->values();

            if ($uniqueCategory->isNotEmpty()) {
                $response = new ResponseResource(false, "category ada yang beda", $categoryOnly);
                return $response->response()->setStatusCode(422);
            }

            // Generate document code
            $code_document = $this->generateDocumentCode();
            while (Document::where('code_document', $code_document)->exists()) {
                $code_document = $this->generateDocumentCode();
            }

            $duplicateBarcodes = collect();
            // Process in chunks
            for ($i = 1; $i < count($ekspedisiData); $i += $chunkSize) {
                $chunkData = array_slice($ekspedisiData, $i, $chunkSize);
                $newProductsToInsert = [];

                foreach ($chunkData as $dataItem) {
                    $newProductDataToInsert = [];

                    foreach ($headerMappings as $key => $headerName) {
                        $columnKey = array_search($headerName, $ekspedisiData[1]);
                        if ($columnKey !== false) {
                            $value = trim($dataItem[$columnKey]);

                            if ($key === 'new_quantity_product') {
                                $quantity = $value !== '' ? (int) $value : 0;
                                $newProductDataToInsert[$key] = $quantity;
                            } elseif (in_array($key, ['old_price_product', 'display_price', 'new_price_product'])) {
                                $cleanedValue = str_replace(',', '', $value);
                                $newProductDataToInsert[$key] = (float) $cleanedValue;
                            } else {
                                $newProductDataToInsert[$key] = $value;
                            }
                        }
                    }

                    if (isset($newProductDataToInsert['new_barcode_product'])) {
                        $barcodeToCheck = $newProductDataToInsert['new_barcode_product'];
                        $sources = $this->checkDuplicateBarcode($barcodeToCheck);

                        if (!empty($sources)) {
                            $duplicateBarcodes->push($barcodeToCheck . ' - ' . implode(', ', $sources));
                        }
                    }

                    if (isset($newProductDataToInsert['old_barcode_product'], $newProductDataToInsert['new_name_product'])) {
                        $newProductsToInsert[] = array_merge($newProductDataToInsert, [
                            'code_document' => $code_document,
                            'new_discount' => 0,
                            'is_so' => null,
                            'new_tag_product' => null,
                            'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                            'type' => 'type1',
                            'user_id' => $user_id,
                            'new_quality' => json_encode(['lolos' => 'lolos']),
                            'actual_new_quality' => json_encode(['lolos' => 'lolos']),
                            'actual_old_price_product' => $newProductDataToInsert['old_price_product'],
                            'created_at' =>  Carbon::now('Asia/Jakarta')->toDateString(),
                            'updated_at' => Carbon::now('Asia/Jakarta')->toDateString(),
                        ]);
                        $count++;
                    }
                }

                if ($duplicateBarcodes->isNotEmpty()) {
                    $response = new ResponseResource(false, "List data barcode yang duplikat", $duplicateBarcodes);
                    return $response->response()->setStatusCode(422);
                }

                // Insert new product data in chunks
                if (!empty($newProductsToInsert)) {
                    New_product::insert($newProductsToInsert);
                }
            }

            // $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            // if ($checkSoCategory) {
            //     $checkSoCategory->increment('product_inventory', count($ekspedisiData) - 1);
            // }

            Document::create([
                'code_document' => $code_document,
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings),
                'total_column_in_document' => count($ekspedisiData) - 1, // Subtract 1 for header
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString(),
            ]);

            $totalData = count($ekspedisiData) - 1;
            $totalDataIn = count($ekspedisiData) - 1;

            // Hitung total harga dari semua produk yang diproses
            $totalPrice = StagingProduct::where('code_document', $code_document)->sum('old_price_product');


            // Hitung persentase total data yang masuk
            $percentageTotalData = $totalData > 0 ? ($totalDataIn / $totalData) * 100 : 0;

            $history = RiwayatCheck::create([
                'user_id' => $user_id,
                'code_document' => $code_document,
                'base_document' => $fileName,
                'total_data' => $totalDataIn,
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $totalDataIn,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'display',
                'precentage_total_data' => round($percentageTotalData, 2),
                'percentage_in' => round($percentageTotalData, 2),
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,
                'total_price' => $totalPrice,
                'value_data_lolos' => 0,
                'value_data_damaged' => 0,
                'value_data_abnormal' => 0,
                'value_data_discrepancy' => 0,
                'status_file' => true,
            ]);

            Notification::create([
                'user_id' => $user_id,
                'notification_name' => 'bulking category staging',
                'role' => 'Spv',
                'read_at' => Carbon::now('Asia/Jakarta'),
                'riwayat_check_id' => $history->id,
                'repair_id' => null,
                'status' => 'display',
            ]);

            DB::commit();

            return new ResponseResource(true, "Data berhasil diproses dan disimpan", [
                'code_document' => $code_document,
                'file_name' => $fileName,
                'total_column_count' => count($headerMappings),
                'total_row_count' => count($ekspedisiData) - 1,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }

    private function checkDuplicateBarcode($barcode)
    {
        $sources = [];

        if (StagingProduct::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Product-Staging';
        }

        if (New_product::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Product-Inventory';
        }

        if (StagingApprove::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Staging-Approve';
        }

        if (FilterStaging::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Filter-Staging';
        }

        return $sources;
    }

    public function showRepair(Request $request)
    {
        try {
            $query = $request->get('q');
            $products = New_product::where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_status_product', '!=', 'dump')
                    ->where(function ($q) {
                        $q->where('new_quality->damaged', '!=', null)
                            ->orWhere('new_quality->abnormal', '!=', null);
                    });

                if ($query) {
                    $queryBuilder->where(function ($q) use ($query) {
                        $q->where('old_barcode_product', 'like', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'like', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'like', '%' . $query . '%')
                            ->orWhere('new_name_product', 'like', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%');
                    });
                }
            })
                ->paginate(50);


            if ($products->isEmpty()) {
                return new ResponseResource(false, "Tidak ada data", $products);
            }

            return new ResponseResource(true, "List damaged dan abnormal", $products);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null);
        }
    }


    public function updateRepair(Request $request, $id)
    {
        $user_id = auth()->id();
        $source = $request->query('source', 'new_product');
        try {
            $product = null;

            if ($source === 'staging') {
                $product = StagingProduct::find($id);
            } else {
                $product = New_product::find($id);
            }

            if (!$product) {
                return new ResponseResource(false, "Produk tidak ditemukan di $source", null);
            }

            $quality = json_decode($product->new_quality, true);

            if (isset($quality['lolos'])) {
                return new ResponseResource(false, "Hanya produk yang damaged atau abnormal yang bisa di repair", null);
            }

            if (isset($quality['damaged'])) {
                $quality['damaged'] = null;
            }

            if (isset($quality['abnormal'])) {
                $quality['abnormal'] = null;
            }

            if (isset($quality['non'])) {
                $quality['non'] = null;
            }

            $validator = Validator::make($request->all(), [
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'new_status_product' => 'required|in:display,expired,promo,bundle,palet',
                'new_category_product' => 'nullable|exists:categories,name_category',
                'new_tag_product' => 'nullable|exists:color_tags,name_color'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $inputData = $request->only([
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_date_in_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'type',
            ]);

            $indonesiaTime = Carbon::now('Asia/Jakarta');
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
                    // if (isset($category->max_price_category) && $category->max_price_category > 0) {
                    //     if ($discountAmount > $category->max_price_category) {
                    //         $discountAmount = $category->max_price_category;
                    //     }
                    // }
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;
                    $calculatedPriceFinal = round($calculatedPrice);
                    $inputPrice = $inputData['new_price_product'];

                    if (round($calculatedPriceFinal) != round($inputPrice)) {
                        $errorMsg = "Harga setelah diskon kategori tidak sesuai. Harap periksa kembali.";

                        return (new ResponseResource(false, $errorMsg, null))
                            ->response()->setStatusCode(422);
                    }
                }
            }

            $quality['lolos'] = 'lolos';
            $inputData['new_quality'] = json_encode($quality);
            // $inputData['actual_old_price_product'] = $product->actual_old_price_product ?? $product->old_price_product;
            // $inputData['actual_new_quality'] =  json_encode($quality);
            $inputData['user_id'] = $user_id;
            $inputData['display_price'] = $inputData['new_price_product'];

            if ($inputData['old_price_product'] < 100000) {

                $inputData['new_category_product'] = null;

                $colortag = Color_tag::where('min_price_color', '<=', $inputData['old_price_product'])
                    ->where('max_price_color', '>=', $inputData['old_price_product'])
                    ->select('fixed_price_color', 'name_color')
                    ->first();

                $inputData['new_price_product'] = $colortag->fixed_price_color;
                $inputData['new_tag_product'] = $colortag->name_color;
            }

            $product->update($inputData);

            return new ResponseResource(true, "Berhasil di repair", $inputData);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null);
        }
    }


    public function MultipleUpdateRepair(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:new_products,id'
        ]);

        $ids = $request->input('ids');
        $updatedProducts = [];

        foreach ($ids as $id) {
            $product = New_product::find($id);

            if (!$product) {
                continue;
            }

            $quality = json_decode($product->new_quality, true);

            if (isset($quality['lolos']) && $quality['lolos'] === 'lolos') {
                continue;
            }

            $quality = array_merge($quality, ['damaged' => null, 'abnormal' => null, 'lolos' => 'lolos']); // Reset 'damaged' dan 'abnormal', set 'lolos'

            $product->new_quality = json_encode($quality);
            $product->save();

            $updatedProducts[] = $product;
        }

        if (empty($updatedProducts)) {
            return response()->json(['message' => "Tidak ada produk yang berhasil di-update"], 404);
        }

        return response()->json(['message' => "Produk berhasil di-update", 'data' => $updatedProducts]);
    }

    public function updateAllDamagedOrAbnormal()
    {
        $products = New_product::all()->filter(function ($product) {
            $quality = json_decode($product->new_quality, true);
            return isset($quality['damaged']) || isset($quality['abnormal']);
        });

        foreach ($products as $product) {
            $quality = json_decode($product->new_quality, true);

            unset($quality['damaged'], $quality['abnormal']);
            $quality['lolos'] = 'lolos';

            $product->new_quality = json_encode($quality);
            $product->save();
        }

        return new ResponseResource(true, "Semua produk damaged dan abnormal sudah berhasil di update menjadi lolos", $products);
    }

    public function excelolds()
    {
        $datas = ExcelOld::latest()->paginate(100);
        return new ResponseResource(true, "list product olds", $datas);
    }

    public function updateDump($id)
    {
        $product = New_product::find($id);

        if ($product->new_status_product == 'dump') {
            return new ResponseResource(false, "status product sudah dump", $product);
        }

        if (!$product) {
            return new ResponseResource(false, "Produk tidak ditemukan", null);
        }

        $quality = json_decode($product->new_quality, true);


        if (isset($quality['lolos'])) {
            return new ResponseResource(false, "Hanya produk yang damaged atau abnormal yang bisa di repair", null);
        }

        $product->update(['new_status_product' => 'dump']);

        return new ResponseResource(true, "data product sudah di update", $product);
    }

    public function listDump(Request $request)
    {
        $query = $request->get('q');

        $columns = [
            'id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product',
            'created_at',
            'updated_at',
        ];

        $searchLogic = function ($queryBuilder) use ($query) {
            if ($query) {
                $queryBuilder->where(function ($q) use ($query) {
                    $q->where('old_barcode_product', 'like', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $query . '%')
                        ->orWhere('new_tag_product', 'like', '%' . $query . '%')
                        ->orWhere('new_category_product', 'like', '%' . $query . '%')
                        ->orWhere('new_name_product', 'like', '%' . $query . '%');
                });
            }
        };

        $newProducts = New_product::select($columns)
            ->addSelect(DB::raw("'display' as source"))
            ->where('new_status_product', 'dump')
            ->whereDoesntHave('scrapDocuments')
            ->where($searchLogic);

        $stagingProducts = StagingProduct::select($columns)
            ->addSelect(DB::raw("'staging' as source"))
            ->where('new_status_product', 'dump')
            ->whereDoesntHave('scrapDocuments')
            ->where($searchLogic);

        $migrateProducts = MigrateBulkyProduct::select($columns)
            ->addSelect(DB::raw("'migrate' as source"))
            ->where('new_status_product', 'dump')
            ->whereDoesntHave('scrapDocuments')
            ->where($searchLogic);

        $products = $newProducts
            ->union($stagingProducts)
            ->union($migrateProducts)
            ->paginate(30);

        return (new ResponseResource(true, "List dump product", $products))
            ->response()->setStatusCode(200);
    }

    public function updateStatusToDump(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer',
            'source'     => 'required|in:staging,display,migrate'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $source = $request->source;
        $productId = $request->product_id;

        DB::beginTransaction();
        try {
            $product = null;

            if ($source === 'staging') {
                $product = StagingProduct::find($productId);
            } else if ($source === 'display') {
                $product = New_product::find($productId);
            } else {
                $product = MigrateBulkyProduct::find($productId);
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => "Produk tidak ditemukan di " . ucfirst($source),
                    'resource' => null
                ], 404);
            }

            $previousRackId = $product->rack_id;
            $sourceType = $source;

            $product->update([
                'new_status_product' => 'dump',
                'rack_id' => null
            ]);

            if ($previousRackId) {
                $rack = Rack::find($previousRackId);
                if ($rack) {
                    if ($sourceType === 'staging') {
                        $products = $rack->stagingProducts();
                    } else {
                        $products = $rack->newProducts();
                    }

                    $rack->update([
                        'total_data' => $products->count(),
                        'total_new_price_product' => $products->sum('new_price_product'),
                        'total_old_price_product' => $products->sum('old_price_product'),
                        'total_display_price_product' => $products->sum('display_price'),
                    ]);
                }
            }

            DB::commit();

            return (new ResponseResource(true, "Berhasil mengubah status produk menjadi dump", $product))
                ->response()
                ->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal update status dump: " . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => "Gagal mengubah status: " . $e->getMessage(),
                'resource' => null
            ], 500);
        }
    }

    public function getTagColor(Request $request)
    {
        $query = $request->input('q');
        $page = $request->input('page', 1);
        $perPage = 33;

        try {
            $tagsSummaryQuery = New_product::select('new_tag_product', DB::raw('COUNT(*) as total_data'), DB::raw('SUM(new_price_product) as total_price'))
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->whereNull('is_so')
                ->whereJsonContains('new_quality->lolos', 'lolos')
                ->where('new_status_product', 'display')
                ->orWhere('new_status_product', 'expired')
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'type1');
                })
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($subQuery) use ($query) {
                        $subQuery->where('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                    });
                })
                ->groupBy('new_tag_product');

            $tagsSummary = $tagsSummaryQuery->get()->map(function ($item) {
                return [
                    'tag_name' => $item->new_tag_product,
                    'total_data' => $item->total_data,
                    'total_price' => $item->total_price,
                ];
            });
            $totalPriceAll = $tagsSummary->sum('total_price');

            $productsQuery = New_product::select(
                'id',
                'old_barcode_product',
                'new_name_product',
                'new_date_in_product',
                'new_status_product',
                'new_tag_product',
                'new_price_product'
            )
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->whereNull('is_so')
                ->whereJsonContains('new_quality->lolos', 'lolos')
                ->where('new_status_product', 'display')
                ->orWhere('new_status_product', 'expired')
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'type1');
                })
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($subQuery) use ($query) {
                        $subQuery->where('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                    });
                })
                ->latest();

            $paginatedProducts = $productsQuery->paginate($perPage, ['*'], 'page', $page);

            return new ResponseResource(true, "list product by tag color", [
                "total_data" => $paginatedProducts->total(),
                "total_price_all" => $totalPriceAll,
                "tags_summary" => $tagsSummary,
                "data" => $paginatedProducts,
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function getTagColor2(Request $request)
    {
        $query = $request->input('q');
        $page = $request->input('page', 1);
        $perPage = 33;

        try {
            $tagsSummaryQuery = New_product::select('new_tag_product', DB::raw('COUNT(*) as total_data'), DB::raw('SUM(new_price_product) as total_price'))
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->whereJsonContains('new_quality->lolos', 'lolos')
                ->where('new_status_product', 'display')
                ->orWhere('new_status_product', 'expired')
                ->where('type', 'type2')
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($subQuery) use ($query) {
                        $subQuery->where('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                    });
                })
                ->groupBy('new_tag_product');

            $tagsSummary = $tagsSummaryQuery->get()->map(function ($item) {
                return [
                    'tag_name' => $item->new_tag_product,
                    'total_data' => $item->total_data,
                    'total_price' => $item->total_price,
                ];
            });
            $totalPriceAll = $tagsSummary->sum('total_price');

            $productsQuery = New_product::select(
                'id',
                'old_barcode_product',
                'new_name_product',
                'new_date_in_product',
                'new_status_product',
                'new_tag_product',
                'new_price_product'
            )
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->whereJsonContains('new_quality->lolos', 'lolos')
                ->where('new_status_product', 'display')
                ->orWhere('new_status_product', 'expired')
                ->where('type', 'type2')
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($subQuery) use ($query) {
                        $subQuery->where('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
                    });
                })
                ->latest();

            $paginatedProducts = $productsQuery->paginate($perPage, ['*'], 'page', $page);

            return new ResponseResource(true, "list product by tag color", [
                "total_data" => $paginatedProducts->total(),
                "total_price_all" => $totalPriceAll,
                "tags_summary" => $tagsSummary,
                "data" => $paginatedProducts,
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }


    public function getByCategory(Request $request)
    {
        $query = $request->input('q');
        // $page = $request->input('page', 1);

        try {

            $productQuery = New_product::select(
                'id',
                'new_barcode_product',
                'new_name_product',
                'new_category_product',
                'new_price_product',
                'created_at',
                'new_status_product',
                'display_price',
                'new_date_in_product',
                DB::raw("'display' as source")
            )
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

            $bundleQuery = Bundle::select(
                'id',
                'barcode_bundle as new_barcode_product',
                'name_bundle as new_name_product',
                'category as new_category_product',
                'total_price_custom_bundle as new_price_product',
                'created_at',
                DB::raw("CASE WHEN product_status = 'not sale' THEN 'display' ELSE product_status END as new_status_product"),
                'total_price_custom_bundle as display_price',
                'created_at as new_date_in_product',
                DB::raw("'bundle' as source")
            )
                ->where('total_price_custom_bundle', '>=', 100000)
                ->where('name_color',  NULL)
                ->where('product_status', '!=', 'bundle')
                ->where(function ($type) {
                    $type->whereNull('type')
                        ->orWhere('type', 'type1')
                        ->orWhere('type', 'type2');
                });;

            if ($query) {
                $productQuery->where(function ($queryBuilder) use ($query) {
                    $queryBuilder->where(function ($subQuery) use ($query) {
                        $subQuery->where('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_status_product', 'LIKE', '%' . $query . '%');
                    });
                });
                $bundleQuery->where(function ($dataBundle) use ($query) {
                    $dataBundle->where('name_bundle', 'LIKE', '%' . $query . '%')
                        ->orWhere('barcode_bundle', 'LIKE', '%' . $query . '%')
                        ->orWhere('category', 'LIKE', '%' . $query . '%')
                        ->orWhere('product_status', 'LIKE', '%' . $query . '%');
                });
                $page = 1;
            }

            // $mergedQuery = $productQuery->unionAll($bundleQuery)->orderBy('created_at', 'desc')
            //     ->paginate(33, ['*'], 'page', $page);
            $mergedQuery = $productQuery->unionAll($bundleQuery)->orderBy('new_date_in_product', 'desc')
                ->paginate(33);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(404);
        }

        return new ResponseResource(true, "list product by product category", $mergedQuery);
    }

    public function updatePriceDump(Request $request, $id)
    {
        $product = New_product::find($id);

        if (!$product) {
            return new ResponseResource(false, "id product tidak ditemukan", $product);
        }

        $validator = Validator::make($request->all(), [
            'new_price_product' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $inputData = $request->only([
            'new_price_product',
        ]);

        $indonesiaTime = Carbon::now('Asia/Jakarta');
        $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

        $updateDump = $product->update($inputData);

        return new ResponseResource(true, "New Produk Berhasil di Update", $updateDump);
    }

    public function exportDumpToExcel(Request $request, $id)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        $bundleQcds = BundleQcd::find($id)->load(['product_qcds']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Name bundle',
            'total_price_bundle',
            'total price custom bundle',
            'total product bundle',
            'barcode_bundle',
        ];

        $headers2 = [
            'Name',
            'New Price',
            'Old Price',
            'Qty',
            'Category',
            'Harga Tag Warna',
            'New Barcode'
        ];

        $columnIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex++, 1, $header);
        }

        $currentRow = 2;
        $sheet->setCellValueByColumnAndRow(1, $currentRow, $bundleQcds->name_bundle);
        $sheet->setCellValueByColumnAndRow(2, $currentRow, $bundleQcds->total_price_bundle);
        $sheet->setCellValueByColumnAndRow(3, $currentRow, $bundleQcds->total_price_custom_bundle);
        $sheet->setCellValueByColumnAndRow(4, $currentRow, $bundleQcds->total_product_bundle);
        $sheet->setCellValueByColumnAndRow(5, $currentRow, $bundleQcds->barcode_bundle);

        $currentRow++;

        // Menambahkan baris kosong antara data headers dan headers2
        $currentRow++;

        $columnIndex = 1;
        foreach ($headers2 as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex++, $currentRow, $header);
        }
        foreach ($bundleQcds->product_qcds as $product) {
            $currentRow++;
            $sheet->setCellValueByColumnAndRow(1, $currentRow, $product->new_name_product);
            $sheet->setCellValueByColumnAndRow(2, $currentRow, $product->new_price_product);
            $sheet->setCellValueByColumnAndRow(3, $currentRow, $product->old_price_product);
            $sheet->setCellValueByColumnAndRow(4, $currentRow, $product->new_quantity_product);
            $sheet->setCellValueByColumnAndRow(5, $currentRow, $product->new_category_product);
            $sheet->setCellValueByColumnAndRow(6, $currentRow, $product->new_tag_product);
            $sheet->setCellValueByColumnAndRow(7, $currentRow, $product->new_barcode_product);
        }

        $fileName = "bundleQcd.xlsx";

        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "File siap diunduh.", $downloadUrl);
    }

    public function getLatestPrice(Request $request)
    {
        $category = null;
        $tagwarna = null;
        if ($request['old_price_product'] > 99999) {
            $category = Category::all();
        } else {
            $tagwarna = Color_tag::where('min_price_color', '<=', $request->input('old_price_product'))
                ->where('max_price_color', '>=', $request->input('old_price_product'))
                ->select('fixed_price_color', 'name_color', 'hexa_code_color')->first();
        }

        return new ResponseResource(true, 'list category', ["category" => $category, "warna" => $tagwarna]);
    }

    //khusus super admin
    public function  addProductByAdmin(Request $request)
    {
        $userId = auth()->id();
        $validator = Validator::make($request->all(), [
            // 'new_barcode_product' => 'required|unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'new_status_product' => 'nullable|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'nullable|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color'
        ],  [
            'new_barcode_product.unique' => 'barcode sudah ada'
        ]);

        // $validator->sometimes('new_category_product', 'required', function ($input) {
        //     return $input->new_price_product >= 100000;
        // });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // Logika untuk memproses data
            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');

            $qualityData = [
                'lolos' => $status === 'lolos' ? 'lolos' : null,
                'damaged' => $status === 'damaged' ? $description : null,
                'abnormal' => $status === 'abnormal' ? $description : null,
            ];


            $inputData = $request->only([
                'old_price_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'price_discount',
                'type',
                'user_id',
                'discount_categroy',
                'is_so'

            ]);

            $inputData['new_status_product'] = 'display';
            $inputData['user_id'] = $userId;
            $inputData['is_so'] = null;
            $inputData['user_so'] = null;

            $category = Category::where('name_category', $inputData['new_category_product'])->first();

            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
            $inputData['new_quality'] = json_encode($qualityData);
            $inputData['actual_new_quality'] = json_encode($qualityData);
            $inputData['actual_old_price_product'] = $inputData['old_price_product'];

            if ($status !== 'lolos') {
                $inputData['new_category_product'] = null;
            }

            $inputData['new_discount'] = 0;
            $inputData['type'] = 'type2';
            $inputData['display_price'] = $inputData['new_price_product'];

            $inputData['new_barcode_product'] = generateNewBarcode($inputData['new_category_product']);

            if ($inputData['old_price_product'] < 100000) {
                $newProduct = New_product::create($inputData);
            } else {
                $newProduct = StagingProduct::create($inputData);
            }
            $newProduct['discount_category'] = $category ? $category->discount_category : null;

            $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            $checkSoColor = SummarySoColor::where('type', 'process')->first();

            if ($qualityData['lolos'] != null) {
                if ($checkSoCategory && $inputData['new_category_product'] !== null) {
                    $checkSoCategory->increment('product_inventory');
                }
                if ($checkSoColor && $inputData['new_tag_product'] !== null) {
                    $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                        ->where('color', $inputData['new_tag_product'])
                        ->first();
                    if ($soColor) {
                        $soColor->increment('total_color');
                    }
                }
                // $riwayatCheck->total_data_lolos += 1;
            } else if ($qualityData['damaged'] != null) {
                if ($inputData['old_price_product'] < 100000) {
                    if ($checkSoColor) {
                        $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                            ->where('color', $inputData['new_tag_product'])
                            ->first();
                        if ($soColor) {
                            $soColor->increment('product_damaged');
                        }
                    }
                } else {
                    if ($checkSoCategory) {
                        $checkSoCategory->increment('product_damaged');
                    }
                }
                // $riwayatCheck->total_data_damaged += 1;
            } else if ($qualityData['abnormal'] != null) {
                // $riwayatCheck->total_data_abnormal += 1;
                if ($inputData['old_price_product'] < 100000) {
                    if ($checkSoColor) {
                        $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                            ->where('color', $inputData['new_tag_product'])
                            ->first();
                        if ($soColor) {
                            $soColor->increment('product_abnormal');
                        }
                    }
                } else {
                    if ($checkSoCategory) {
                        $checkSoCategory->increment('product_abnormal');
                    }
                }
            }


            // $this->deleteOldProduct($request->input('old_barcode_product')); 

            DB::commit();

            return new ResponseResource(true, "berhasil menambah data", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function checkPrice(Request $request)
    {
        $totalNewPrice = $request['new_price_product'];

        if ($totalNewPrice < 100000) {
            $tagwarna = Color_tag::where('min_price_color', '<=', $totalNewPrice)
                ->where('max_price_color', '>=', $totalNewPrice)
                ->select('fixed_price_color', 'name_color')->first();

            return new ResponseResource(true, "tag warna", $tagwarna);
        }
    }

    public function totalPerColor(Request $request)
    {
        $new_product = New_product::whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
            ->where('new_status_product', 'display')->pluck('new_tag_product');
        $countByColor = $new_product->countBy(function ($item) {
            return $item;
        });

        if (count($countByColor) < 1) {
            return new ResponseResource(false, "tidak ada data data color", null);
        }
        return new ResponseResource(true, "list data product by color2", $countByColor);
    }

    public function colorDestination(Request $request)
    {
        $new_product = New_product::whereNotNull('new_tag_product')
            ->where('new_category_product', null)
            ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
            ->where('new_status_product', 'display')->pluck('new_tag_product');
        $countByColor = $new_product->countBy(function ($item) {
            return $item;
        });

        if (count($countByColor) < 1) {
            return new ResponseResource(false, "tidak ada data data color", null);
        }
        $destinations = Destination::latest()->get();
        return new ResponseResource(
            true,
            "list data product by color",
            [
                "color" => $countByColor,
                "destinations" => $destinations
            ]
        );
    }

    public function exportProductByColor(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-by-color.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductByColor($request), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }


    public function exportProductByCategory(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-inventory.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductInventoryCtgry($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan asset dari public path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function export_product_expired(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-inventory.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductExpiredSLMP($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan asset dari public path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }


    public function addProductById($id)
    {
        DB::beginTransaction();
        try {
            $product = New_product::findOrFail($id);
            $product->new_barcode_product = generateNewBarcode($product->new_category_product);
            $productFilter = New_product::create($product->toArray());
            DB::commit();
            return new ResponseResource(true, "berhasil menambah product", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function exportCategoryColorNull()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $fileName = 'product-category-color-null.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductCategoryAndColorNull, $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function productAbnormal(Request $request)
    {
        $query = $request->query('q');

        $columns = [
            'id',
            'rack_id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product',
            'created_at',
            'updated_at',
            'new_discount',
            'display_price',
            'type',
            'user_id',
            'is_so',
            'user_so',
            'actual_old_price_product',
            'actual_new_quality'
        ];

        $newProducts = New_product::select($columns)
            ->addSelect(DB::raw("'display' as source"))
            ->whereNotNull('new_quality->abnormal')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        $stagingProducts = StagingProduct::select($columns)
            ->addSelect(DB::raw("'staging' as source"))
            ->whereNotNull('new_quality->abnormal')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        if ($query) {
            $searchLogic = function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%');
            };

            $newProducts->where($searchLogic);
            $stagingProducts->where($searchLogic);
        }

        $data = $newProducts->union($stagingProducts)
            ->orderBy('new_date_in_product', 'desc')
            ->paginate(30);

        return new ResponseResource(true, "list data product by abnormal", $data);
    }

    public function productDamaged(Request $request)
    {
        $query = $request->query('q');

        $columns = [
            'id',
            'rack_id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product',
            'created_at',
            'updated_at',
            'new_discount',
            'display_price',
            'type',
            'user_id',
            'is_so',
            'user_so',
            'actual_old_price_product',
            'actual_new_quality'
        ];

        $newProducts = New_product::select($columns)
            ->addSelect(DB::raw("'display' as source"))
            ->whereNotNull('new_quality->damaged')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        $stagingProducts = StagingProduct::select($columns)
            ->addSelect(DB::raw("'staging' as source"))
            ->whereNotNull('new_quality->damaged')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        if ($query) {
            $searchLogic = function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%');
            };

            $newProducts->where($searchLogic);
            $stagingProducts->where($searchLogic);
        }

        $data = $newProducts->union($stagingProducts)
            ->orderBy('new_date_in_product', 'desc')
            ->paginate(30);

        return new ResponseResource(true, "list data product by damaged", $data);
    }

    public function productNon(Request $request)
    {
        $query = $request->query('q');

        $columns = [
            'id',
            'rack_id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product',
            'created_at',
            'updated_at',
            'new_discount',
            'display_price',
            'type',
            'user_id',
            'is_so',
            'user_so',
            'actual_old_price_product',
            'actual_new_quality'
        ];

        $newProducts = New_product::select($columns)
            ->addSelect(DB::raw("'display' as source"))
            ->whereNotNull('new_quality->non')
            ->whereNull('is_so')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        $stagingProducts = StagingProduct::select($columns)
            ->addSelect(DB::raw("'staging' as source"))
            ->whereNotNull('new_quality->non')
            ->whereNotIn('new_status_product', ['migrate', 'sale', 'dump', 'scrap_qcd']);

        if ($query) {
            $searchLogic = function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%');
            };

            $newProducts->where($searchLogic);
            $stagingProducts->where($searchLogic);
        }

        $data = $newProducts->union($stagingProducts)
            ->orderBy('new_date_in_product', 'desc')
            ->paginate(30);

        return new ResponseResource(true, "list data product by damaged", $data);
    }


    public function exportDamaged(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $fileName = 'product-damaged-' . Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductDamagedExport($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportAbnormal(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $fileName = 'product-abnormal-' . Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductAbnormalExport($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportNon(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $fileName = 'product-non-' . Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductNonExport($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportMasSugeng(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-abnormal-' . Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductExportMasSugeng($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportTemplate(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'Template-bulking-category.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new TemplateBulkingCategory($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }
}
