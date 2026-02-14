<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\User;
use App\Models\Category;
use App\Models\Document;
use App\Models\Color_tag;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\ApproveQueue;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\FilterStaging;
use GuzzleHttp\Psr7\Response;
use App\Models\ProductApprove;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ProductsExportCategory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Resources\ResponseResource;
use App\Imports\StagingProductImport;
use App\Models\MigrateBulky;
use App\Models\MigrateBulkyProduct;
use App\Models\ProductDefect;
use App\Models\Rack;
use App\Models\SummarySoCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class StagingProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $searchQuery = $request->input('q');
        // $page = $request->input('page', 1);
        try {
            // Buat query dasar untuk StagingProduct
            $newProductsQuery = StagingProduct::query()
                ->select(
                    'id',
                    'new_barcode_product',
                    'new_name_product',
                    'new_category_product',
                    'new_price_product',
                    'new_status_product',
                    'display_price',
                    'new_date_in_product',
                    'stage',
                    'is_so',
                    DB::raw("'staging' as source")
                )
                ->whereNotIn('new_status_product', ['dump', 'sale', 'migrate', 'repair', 'scrap_qcd'])
                ->where(function ($query) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
                })
                ->whereNull('new_tag_product')
                ->whereNull('stage')
                ->whereNotNull('new_category_product') //  diperbarui dari lokal
                ->whereNot('new_category_product', '') // diperbarui dari lokal
                ->latest();

            if ($searchQuery) {
                $newProductsQuery->where(function ($queryBuilder) use ($searchQuery) {
                    $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
                });
            }

            // Terapkan pagination setelah pencarian selesai
            // $paginatedProducts = $newProductsQuery->paginate(33, ['*'], 'page', $page);
            $paginatedProducts = $newProductsQuery
                ->orderBy('new_date_in_product', 'desc')
                ->paginate(50);

            $paginatedProducts->getCollection()->transform(function ($item) {
                $item->status_so = ($item->is_so === 'done') ? 'Sudah SO' : 'Belum SO';

                return $item;
            });

            return new ResponseResource(true, "List of new products", $paginatedProducts);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product_filters = StagingProduct::where('user_id', $userId)->where('stage', 'process')->get();
            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }

            $insertData = $product_filters->map(function ($product) use ($userId) {
                $product->update([
                    'stage' => 'approve',
                    'updated_at' => now()
                ]);
                return $product;
            });

            Notification::create([
                'user_id' => $userId,
                'notification_name' => 'butuh approvemend untuk product staging',
                'role' => 'Spv',
                'read_at' => Carbon::now('Asia/Jakarta'),
                'riwayat_check_id' => null,
                'repair_id' => null,
                'status' => 'done',
            ]);

            logUserAction($request, $request->user(), "stagging/list_product_stagging", "to staging approve");

            DB::commit();
            return new ResponseResource(true, "staging approve berhasil dibuat", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke approve', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(StagingProduct $stagingProduct)
    {
        $category = Category::where('name_category', $stagingProduct['new_category_product'])->first();
        $stagingProduct['discount_category'] = $category ? $category->discount_category : null;
        $approveQueue = ApproveQueue::where('product_id', $stagingProduct->id)->where('status', '1')->first();
        if ($approveQueue) {
            $stagingProduct['status'] = 'not_editable';
        } else {
            $stagingProduct['status'] = 'editable';
        }
        return new ResponseResource(true, "data new product", $stagingProduct);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(StagingProduct $stagingProduct)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StagingProduct $stagingProduct)
    {
        DB::beginTransaction();
        try {
            $checkApproveQueue = ApproveQueue::where('type', 'staging')->where('product_id', $stagingProduct->id)->where('status', '1')->first();
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
                'condition' => 'required|in:lolos,damaged,abnormal,non',
                'new_category_product' => 'nullable',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'new_discount',
                'display_price',
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
                'non' => $status === 'non' ? $description : null,
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
                'display_price',
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
                // Set nilai-nilai default jika status bukan 'lolos'
                $inputData['new_price_product'] = null;
                $inputData['new_category_product'] = null;
            }

            $inputData['new_quality'] = json_encode($qualityData);
            // $inputData['display_price'] = $inputData['new_price_product'];

            $userRole = User::where('id', auth()->id())->first();

            $original_barcode = $stagingProduct->new_barcode_product;
            $original_new_price = $stagingProduct->new_price_product;
            $original_old_price = $stagingProduct->old_price_product;

            if ($userRole->role->role_name != 'Admin' && $userRole->role->role_name != 'Spv') {
                $response = ApproveQueue::create([
                    'user_id' => auth()->id(),
                    'product_id' => $stagingProduct->id,
                    'type' => 'staging',
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
                    'notification_name' => "edit product staging" . " " . $inputData['new_barcode_product'],
                    'role' => 'Spv',
                    'read_at' => Carbon::now('Asia/Jakarta'),
                    'riwayat_check_id' => null,
                    'repair_id' => null,
                    'status' => 'staging',
                    'external_id' => $stagingProduct->id,
                    'approved' => '0'
                ]);

                logUserAction(
                    $request,
                    $request->user(),
                    "staging/product/detail",
                    "Barcode: " . $inputData['new_barcode_product'] .
                        ", New Price: " . $inputData['new_price_product'] .
                        ", Old Price: " . $inputData['old_price_product'] .
                        ". Data Before Edit" .
                        ", Before Edit Barcode: " . $original_barcode .
                        ", Before Edit New Price: " . $original_new_price .
                        ", Before Edit Old Price: " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );
            } else {
                $response = $stagingProduct->update($inputData);
                $stagingProduct->save();
                logUserAction(
                    $request,
                    $request->user(),
                    "staging/product/detail",
                    "Barcode: " . $inputData['new_barcode_product'] .
                        ", New Price: " . $inputData['new_price_product'] .
                        ", Old Price: " . $inputData['old_price_product'] .
                        ". Data Before Edit ->" .
                        " Before Edit Barcode: " . $original_barcode .
                        ", Before Edit New Price: " . $original_new_price .
                        ", Before Edit Old Price: " . $original_old_price .
                        " wait for update product approve by spv" . $user
                );
            }

            DB::commit();
            return new ResponseResource(true, "New Produk Berhasil di Update", $response);
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
    public function destroy(StagingProduct $stagingProduct)
    {
        //
    }

    public function addStagingToSpv(Request $request)
    {
        DB::beginTransaction();
        $user = auth()->user();
        try {
            $riwayat_check = RiwayatCheck::where('code_document', $request['code_document'])->first();
            if ($riwayat_check->status_approve == 'done') {
                $notif_count = Notification::where('riwayat_check_id', $riwayat_check->id)
                    ->where('status', 'staging')
                    ->count();
                if ($notif_count >= 1) {
                    $response = new ResponseResource(false, "Data sudah ada", null);
                    return $response->response()->setStatusCode(422);
                } else {
                    //keterangan transaksi
                    $keterangan = Notification::create([
                        'user_id' => $user->id,
                        'notification_name' => 'butuh approvemend untuk product staging',
                        'role' => 'Spv',
                        'read_at' => Carbon::now('Asia/Jakarta'),
                        'riwayat_check_id' => $riwayat_check->id,
                        'repair_id' => null,
                        'status' => 'staging',
                    ]);
                    $riwayat_check->update(['status_approve', 'staging']);
                    DB::commit();
                }
            }
            return new ResponseResource(true, "Data berhasil ditambah", [
                $riwayat_check,
                $keterangan,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $resource = new ResponseResource(false, "Data gagal ditambahkan, terjadi kesalahan pada server : " . $e->getMessage(), null);
            $resource->response()->setStatusCode(500);
        }
    }

    public function documentsApproveStaging(Request $request)
    {
        $query = $request->input('q');

        // Mengambil data dari tabel notifications yang berkaitan dengan riwayat_check
        $notifQuery = Notification::with('riwayat_check')->where('status', 'staging')
            ->whereHas('riwayat_check', function ($q) use ($query) {
                if (!empty($query)) {
                    $q->where('status_approve', $query);
                } else {
                    $q->where('status_approve', 'done');
                }
            })
            ->latest();

        // Eksekusi query dan kembalikan hasilnya
        $notifications = $notifQuery->get();

        return new ResponseResource(true, "List of documents in staging", $notifications);
    }

    public function productStagingByDoc(Request $request, $code_document)
    {
        $query = $request->input('q');
        $user = User::with('role')->find(auth()->id());

        if ($user) {
            $productsQuery = StagingProduct::where('code_document', $code_document);

            if (!empty($query)) {
                $productsQuery->where('new_name_product', 'LIKE', '%' . $query . '%');
            }

            $products = $productsQuery->paginate(50);

            return new ResponseResource(true, 'products', $products);
        } else {
            return (new ResponseResource(false, "User tidak dikenali", null))->response()->setStatusCode(404);
        }
    }

    public function documentStagings(Request $request)
    {
        $query = $request->input('q');

        // Mengambil data notifikasi yang statusnya 'staging' dan menyertakan relasi 'riwayat_check'
        $notifQuery = Notification::with('riwayat_check')->where('status', 'staging')->latest();

        // Jika query tidak kosong, lakukan pencarian berdasarkan 'base_document' atau 'code_document'
        if (!empty($query)) {
            $notifQuery->whereHas('riwayat_check', function ($q) use ($query) {
                $q->where('base_document', $query)
                    ->orWhere('code_document', $query); // Memperbaiki typo dari 'cpde_document' menjadi 'code_document'
            });
        } else {
            // Jika tidak ada query, lakukan pencarian berdasarkan 'status_approve' dengan nilai 'pending' atau 'done'
            $notifQuery->whereHas('riwayat_check', function ($q) {
                $q->where('status_approve', 'pending')
                    ->orWhere('status_approve', 'done');
            });
        }

        // Ambil semua data yang sesuai
        $documents = $notifQuery->get();

        return new ResponseResource(true, "Document Approves", $documents);
    }

    protected function generateDocumentCode()
    {
        // Generate 4 digit random number (1000-9999)
        $randomId = rand(1000, 9999);
        $month = date('m');
        $year = date('Y');
        return $randomId . '/' . $month . '/' . $year;
    }

    protected function isCodeDocumentExists($code_document)
    {
        // Check di semua model yang menggunakan code_document
        return Document::where('code_document', $code_document)->exists() ||
            StagingProduct::where('code_document', $code_document)->exists() ||
            New_product::where('code_document', $code_document)->exists() ||
            ProductApprove::where('code_document', $code_document)->exists() ||
            Product_old::where('code_document', $code_document)->exists() ||
            Sale::where('code_document', $code_document)->exists();
    }

    protected function generateUniqueDocumentCode()
    {
        $maxAttempts = 100; // Hindari infinite loop
        $attempts = 0;

        do {
            $code_document = $this->generateDocumentCode();
            $attempts++;

            if ($attempts >= $maxAttempts) {
                throw new \Exception("Tidak dapat generate code_document yang unik setelah {$maxAttempts} percobaan");
            }
        } while ($this->isCodeDocumentExists($code_document));

        return $code_document;
    }

    public function  processExcelFilesCategoryStaging(Request $request)
    {
        $user_id = auth()->id();
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

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

            $headersFromFile = $ekspedisiData[1]; // baris pertama (index 1) adalah header

            // Header yang diharapkan untuk format baru
            $expectedHeaders = [
                'Barcode',
                'Description',
                'Category',
                'Qty',
                'Unit Price',
                'Bast',
                'Discount',
                'Price After Discount',
            ];

            // Periksa apakah header sesuai
            if (array_diff($expectedHeaders, $headersFromFile) || array_diff($headersFromFile, $expectedHeaders)) {
                $response = new ResponseResource(false, "header tidak sesuai, berikut header yang benar : ", $expectedHeaders);
                return $response->response()->setStatusCode(422);
            }


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

            // Generate unique document code
            $code_document = $this->generateUniqueDocumentCode();

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
                            'new_status_product' => 'display',
                            'is_so' => "done",
                            'user_so' =>  $user_id,
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
                    StagingProduct::insert($newProductsToInsert);
                }
            }

            Document::create([
                'code_document' => $code_document,
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings),
                'total_column_in_document' => count($ekspedisiData) - 1, // Subtract 1 for header
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString(),
            ]);

            // $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            // if($checkSoCategory){
            //     $checkSoCategory->increment('product_staging', count($ekspedisiData) - 1);
            // }

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

        if (Sale::where('product_barcode_sale', $barcode)->exists()) {
            $sources[] = 'sale';
        }

        return $sources;
    }

    public function partial($code_document)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $document = Document::where('code_document', $code_document)->first();
            if ($document) {

                $productApprovesTags = ProductApprove::where('code_document', $code_document)
                    ->whereNotNull('new_tag_product')->where('new_quality->lolos', '!=', null)
                    ->get();

                $productApprovesCategories = ProductApprove::where('code_document', $code_document)
                    ->whereNull('new_tag_product')->where('new_quality->lolos', '!=', null)
                    ->get();

                $productApprovesAD = ProductApprove::where('code_document', $code_document)
                    ->where('new_quality->abnormal', '!=', null)
                    ->orWhere('new_quality->damaged', '!=', null)
                    ->orWhereNotNull('new_quality->non', '!=', null)
                    ->get();

                DB::beginTransaction();

                $this->processProductApproves($productApprovesAD, New_product::class, 100);
                $this->processProductApproves($productApprovesTags, New_product::class, 100);
                $this->processProductApproves($productApprovesCategories, StagingProduct::class, 200);

                $total = count($productApprovesTags) + count($productApprovesCategories);

                DB::commit();
                return new ResponseResource(true, "Berhasil ke staging", $total);
            } else {
                return new ResponseResource(false, "Code document tidak ada", $code_document);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal mengapprove transaksi", $e->getMessage());
        }
    }

    private function processProductApproves($productApproves, $modelClass, $chunkSize)
    {
        $productApproves->chunk($chunkSize)->each(function ($chunk) use ($modelClass) {
            $dataToInsert = [];

            foreach ($chunk as $productApprove) {
                $dataToInsert[] = [
                    'code_document' => $productApprove->code_document,
                    'old_barcode_product' => $productApprove->old_barcode_product,
                    'new_barcode_product' => $productApprove->new_barcode_product,
                    'new_name_product' => $productApprove->new_name_product,
                    'new_quantity_product' => $productApprove->new_quantity_product,
                    'new_price_product' => $productApprove->new_price_product,
                    'old_price_product' => $productApprove->old_price_product,
                    'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                    'new_status_product' => 'display',
                    'new_quality' => $productApprove->new_quality,
                    'actual_new_quality' => $productApprove->actual_new_quality ?? $productApprove->new_quality,
                    'actual_old_price_product' => $productApprove->actual_old_price_product ?? $productApprove->old_price_product,
                    'new_category_product' => $productApprove->new_category_product,
                    'new_tag_product' => $productApprove->new_tag_product,
                    'new_discount' => $productApprove->new_discount,
                    'display_price' => $productApprove->display_price,
                    'type' => $productApprove->type,
                    'user_id' => $productApprove->user_id,
                    'is_so' => "done",
                    'user_so' => $productApprove->user_id,
                    'created_at' => $productApprove->created_at,
                    'updated_at' => now(),
                ];
            }

            $modelClass::insert($dataToInsert);

            ProductApprove::destroy($chunk->pluck('id'));
        });
    }

    public function export()
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        try {
            $fileName = 'product-staging.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductsExportCategory(StagingProduct::class), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function toLpr(Request $request, $id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required',
                'description' => 'required',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors(),
                ], 422);
            }

            $product = StagingProduct::findOrFail($id);
            $product->user_id = $userId;
            $product->created_at = now();
            $product->updated_at = now();

            $new_quality = $this->prepareQualityData($request['status'], $request['description']);
            $product->new_quality = json_encode($new_quality);

            $duplicate = New_product::where('new_barcode_product', $product->new_barcode_product)->exists();
            if ($duplicate) {
                return new ResponseResource(false, "barcode product di inventory sudah ada : " . $product->new_barcode_product, null);
            }

            $productFilter = New_product::create($product->toArray());
            $product->delete();

            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product staging", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function toMigrate(Request $request, $id)
    {
        DB::beginTransaction();
        $userId = auth()->id();

        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable',
                'description' => 'nullable',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $product = StagingProduct::findOrFail($id);
            $stagingId = $product->id;

            $migrateBulky = MigrateBulky::where('user_id', $userId)
                ->where('status_bulky', 'proses')
                ->first();

            $codeDocument = null;

            if (!$migrateBulky) {
                $now = \Carbon\Carbon::now();
                $dateSuffix = $now->format('m/d');

                $lastRecord = MigrateBulky::where('code_document', 'LIKE', '%/' . $dateSuffix)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($lastRecord) {
                    $parts = explode('/', $lastRecord->code_document);
                    $nextNumber = intval($parts[0]) + 1;
                } else {
                    $nextNumber = 1;
                }

                $codeDocument = str_pad($nextNumber, 4, '0', STR_PAD_LEFT) . '/' . $dateSuffix;

                $migrateBulky = MigrateBulky::create([
                    'code_document' => $codeDocument,
                    'user_id' => $userId,
                    'name_user' => auth()->user()->name,
                    'status_bulky' => 'proses',
                    'total_product' => 0,
                    'total_price' => 0,
                ]);
            } else {
                $codeDocument = $migrateBulky->code_document;
            }
            $productData = $product->toArray();

            unset($productData['id']);
            unset($productData['created_at']);
            unset($productData['updated_at']);

            $productData['migrate_bulky_id'] = $migrateBulky->id;
            $productData['code_document'] = $codeDocument;
            $productData['new_status_product'] = 'migrate';
            $productData['user_id'] = $userId;
            $productData['new_product_id'] = $stagingId;

            if ($request->filled('status')) {
                $new_quality = $this->prepareQualityData($request['status'], $request['description']);
                $productData['new_quality'] = json_encode($new_quality);
            }

            $exists = MigrateBulkyProduct::where('migrate_bulky_id', $migrateBulky->id)
                ->where('new_barcode_product', $product->new_barcode_product)
                ->exists();

            if ($exists) {
                return new ResponseResource(false, "Produk ini sudah ada di list migrasi Anda.", null);
            }

            $migratedProduct = MigrateBulkyProduct::create($productData);

            $previousRackId = $product->rack_id;
            $sourceType = "staging";

            $product->update([
                'new_status_product' => 'migrate',
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

            return new ResponseResource(true, "Berhasil memindahkan produk ke List Migrate", $migratedProduct);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function prepareQualityData($status, $description)
    {
        return [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null,
            'non' => $status === 'non' ? $description : null,
        ];
    }

    public function importProductApprove(Request $request)
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
                'code_document' => 'Code Document',
                'old_barcode_product' => 'Old Barcode',
                'new_barcode_product' => 'New Barcode',
                'new_name_product' => 'Name Product',
                'new_category_product' => 'Category',
                'new_quantity_product' => 'Qty',
                'new_price_product' => 'After Diskon',
                'old_price_product' => 'Unit Price',
                'new_date_in_product' => 'Date',
                'display_price' => 'After Diskon',
                'new_quality' => 'Keterangan',
            ];

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
                            } elseif (in_array($key, ['old_price_product', 'display_price', 'new_price_product'])) {
                                $cleanedValue = str_replace(',', '', $value);
                                $newProductDataToInsert[$key] = (float)$cleanedValue;
                            } elseif ($key === 'new_category_product') {
                                $category = $value === null ? null : $value;
                                $newProductDataToInsert[$key] = $category;
                            } else {
                                $newProductDataToInsert[$key] = $value;
                            }
                        }
                    }

                    // **Validasi dan manipulasi sebelum insert**
                    if (isset($newProductDataToInsert['new_quality'])) {
                        if ($newProductDataToInsert['new_quality'] !== 'lolos') {
                            $newProductDataToInsert['new_quality'] = json_encode(['damaged' => $newProductDataToInsert['new_quality']]);
                        } else {
                            $newProductDataToInsert['new_quality'] = json_encode(['lolos' => 'lolos']);
                        }
                    } else {
                        $newProductDataToInsert['new_quality'] = json_encode(['unknown' => 'unknown']);
                    }

                    if (isset($newProductDataToInsert['old_price_product']) && $newProductDataToInsert['old_price_product'] < 100000) {
                        $tagwarna = Color_tag::where('min_price_color', '<=', $newProductDataToInsert['old_price_product'])
                            ->where('max_price_color', '>=', $newProductDataToInsert['old_price_product'])
                            ->select('fixed_price_color', 'name_color', 'hexa_code_color')
                            ->first();

                        if ($tagwarna) {
                            $newProductDataToInsert['new_tag_product'] = $tagwarna->name_color;
                        } else {
                            $newProductDataToInsert['new_tag_product'] = null;
                        }
                    } else {
                        $newProductDataToInsert['new_tag_product'] = null;
                    }

                    if (!empty($newProductDataToInsert['old_barcode_product']) && !empty($newProductDataToInsert['new_name_product'])) {
                        $newProductsToInsert[] = array_merge($newProductDataToInsert, [
                            'new_discount' => 0,
                            'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                            'type' => 'type1',
                            'user_id' => $user_id,
                            'created_at' => Carbon::now('Asia/Jakarta')->toDateTimeString(),
                            'updated_at' => Carbon::now('Asia/Jakarta')->toDateTimeString(),
                        ]);
                        $count++;
                    }
                }

                if (!empty($newProductsToInsert)) {
                    ProductApprove::insert($newProductsToInsert);
                }
            }
            Document::create([
                'code_document' => '0000/12/2024',
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings),
                'total_column_in_document' => count($ekspedisiData) - 1, // Subtract 1 for header
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString(),
            ]);

            DB::commit();

            return new ResponseResource(true, "Data berhasil diproses dan disimpan", [
                'code_document' => $newProductDataToInsert['code_document'] ?? null,
                'file_name' => $fileName,
                'total_column_count' => count($headerMappings),
                'total_row_count' => count($ekspedisiData) - 1,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }

    public function batchToLpr(Request $request)
    {
        $totalProcessed = 0;
        StagingProduct::whereNotNull('new_quality->abnormal')
            ->orWhereNotNull('new_quality->damaged')
            ->chunk(1000, function ($products) use (&$totalProcessed) {
                $newProducts = [];

                foreach ($products as $product) {
                    $newProducts[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->old_barcode_product,
                        'new_barcode_product' => $product->new_barcode_product,
                        'new_name_product' => $product->new_name_product,
                        'new_quantity_product' => $product->new_quantity_product,
                        'old_price_product' => $product->old_price_product,
                        'new_price_product' => $product->new_price_product,
                        'new_date_in_product' => $product->new_date_in_product,
                        'new_status_product' => 'display',
                        'new_quality' => $product->new_quality,
                        'new_category_product' => $product->new_category_product,
                        'new_tag_product' => $product->new_tag_product,
                        'new_discount' => $product->new_discount,
                        'display_price' => $product->display_price,
                        'created_at' => $product->created_at,
                        'updated_at' => $product->updated_at,
                        'type' => $product->type ?? null,
                        'user_id' => $product->user_id ?? null,
                    ];
                }

                if (!empty($newProducts)) {
                    DB::table('new_products')->insert($newProducts);
                    $totalProcessed += count($newProducts);
                }
            });

        return new ResponseResource(true, "Berhasil dipindahkan", $totalProcessed);
    }

    public function deleteToLprBatch()
    {
        try {
            // Inisialisasi variabel untuk menghitung total data yang dihapus
            $totalDeleted = 0;

            // Gunakan chunk untuk memproses data dalam kelompok
            StagingProduct::whereNotNull('new_quality->abnormal')
                ->orWhereNotNull('new_quality->damaged')
                ->chunk(1000, function ($products) use (&$totalDeleted) {
                    // Loop melalui setiap produk dalam chunk dan hapus
                    foreach ($products as $product) {
                        $product->delete();
                        $totalDeleted++;
                    }
                });

            // Kembalikan respons berhasil
            return response()->json([
                'success' => true,
                'message' => "Successfully deleted {$totalDeleted} products."
            ]);
        } catch (\Exception $e) {
            // Tangani error dan kembalikan respons gagal
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete products.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function countPrice()
    {
        // Query untuk menghitung data produk
        $query = StagingProduct::query()
            ->select(
                'id',
                'new_price_product'
            )
            ->whereNotIn('new_status_product', ['dump', 'expired', 'sale', 'migrate', 'repair'])
            ->whereNull('new_tag_product')
            ->whereNotNull('new_category_product') // diperbarui dari lokal
            ->whereNot('new_category_product', '') // diperbarui dari lokal
            ->whereNull('stage');

        // Hitung jumlah total produk
        $totalProduct = $query->count();

        // Hitung total harga produk
        $totalPrice = $query->sum('new_price_product');

        // Kembalikan response dalam format yang diinginkan
        return new ResponseResource(true, "list", [
            "total_product" => $totalProduct,
            "total_price" => $totalPrice,
        ]);
    }

    public function expireProductStaging()
    {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');
        // $fourWeeksAgo = now()->subWeeks(4)->toDateString();
        DB::beginTransaction();

        try {
            $ninetyDaysAgo = now()->subDays(91)->toDateString();


            $products = StagingProduct::where('new_date_in_product', '<=', $ninetyDaysAgo)
                ->where('new_status_product', 'display')
                ->get();

            foreach ($products as $product) {
                $product->update(['new_status_product' => 'expired']);
            }
            DB::commit();
            return new ResponseResource(true, "Products expired successfully", $products);
        } catch (\Exception $e) {
            DB::rollback();
            return (new ResponseResource(false, "Products slow_moving successfully", []))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function slowMovingProductStaging()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();

        try {
            // $fourWeeksAgo = now()->subWeeks(4)->toDateString();
            $daysAgo = now()->subDays(60)->toDateString();

            $products = StagingProduct::where('new_date_in_product', '<=', $daysAgo)
                ->where('new_status_product', 'display')
                ->get();

            foreach ($products as $product) {
                $product->update(['new_status_product' => 'slow_moving']);
            }

            DB::commit();
            Log::info("Cron job Berhasil di jalankan " . date('Y-m-d H:i:s'));

            return new ResponseResource(true, "Products slow_moving successfully", $products);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Products slow_moving successfully", []))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function updateProductFromHistory(Request $request, $table, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'actual_old_price_product' => 'nullable|numeric',
                'condition' => 'nullable|in:lolos,damaged,abnormal,non',
                'deskripsi' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return new ResponseResource(false, "Validation failed", $validator->errors());
            }

            // Find product based on table
            $product = null;
            if ($table === 'staging_products') {
                $product = StagingProduct::find($id);
            } elseif ($table === 'new_products') {
                $product = New_product::find($id);
            } elseif ($table === 'product_approves') {
                $product = ProductApprove::find($id);
            } elseif ($table === 'sales') {
                $product = Sale::find($id);
            } else {
                return new ResponseResource(false, "Tabel tidak valid", null);
            }

            if (!$product) {
                return new ResponseResource(false, "Product tidak ditemukan", null);
            }

            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');


            if ($request->input('condition') === 'lolos') {
                $description = 'lolos';
                $qualityData = $this->prepareQualityData($status, $description);

                // Prepare update data
                $updateData = [
                    'actual_old_price_product' => $request->input('actual_old_price_product'),
                    'actual_new_quality' => json_encode($qualityData),
                ];

                // For sales table, use different column names
                if ($table === 'sales') {
                    $updateData = [
                        'actual_product_old_price_sale' => $request->input('actual_old_price_product'),
                        'actual_status_product' => 'display',
                    ];
                }

                ProductDefect::where('code_document', $product->code_document)
                    ->where('old_barcode_product', $product->old_barcode_product)
                    ->delete();
            } else {
                $qualityData = $this->prepareQualityData($status, $description);
                // Prepare update data
                $updateData = [
                    'actual_old_price_product' => $request->input('actual_old_price_product'),
                    'actual_new_quality' => json_encode($qualityData),
                ];

                // For sales table, use different column names
                if ($table === 'sales') {
                    $updateData = [
                        'actual_product_old_price_sale' => $request->input('actual_old_price_product'),
                        'actual_status_product' => $status,
                    ];
                }
            }

            // Update the product
            $product->update($updateData);

            return new ResponseResource(true, "Berhasil mengupdate product dari history", $product);
        } catch (Exception $e) {
            return new ResponseResource(false, "Gagal mengupdate product dari history", $e->getMessage());
        }
    }

    public function importExcel(Request $request)
    {
        set_time_limit(0);

        ini_set('memory_limit', '-1');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $importer = new StagingProductImport;

            Excel::import($importer, $request->file('file'));

            $duplicateList = $importer->getDuplicates();
            $countDuplicates = count($duplicateList);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Proses import selesai.',
                'total_skipped' => $countDuplicates,
                'skipped_data' => $duplicateList,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Gagal import data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
