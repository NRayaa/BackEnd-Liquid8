<?php

namespace App\Http\Controllers;

use App\Exports\ProductInputExport;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Category;
use App\Models\UserScan;
use App\Models\ColorTag2;
use App\Models\New_product;
use App\Models\ProductScan;
use App\Models\ProductInput;
use Illuminate\Http\Request;
use App\Models\FormatBarcode;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use App\Models\FilterProductInput;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;



class ProductInputController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    
    public function index(Request $request)
    {
        $searchQuery = $request->input('q');
        $newProducts = ProductInput::latest()
            ->where(function ($queryBuilder) use ($searchQuery) {
                $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });
        $totalPrice = $newProducts->sum('new_price_product');
        $newProducts = $newProducts->paginate(50);
        return new ResponseResource(true, "list product scans", ['tota_price' => $totalPrice, 'products' => $newProducts]);
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
        $userId = auth()->id();
    
        // Validasi
        $validator = Validator::make($request->all(), [
            'new_barcode_product' => 'nullable',
            'new_name_product' => 'required',
            'new_quantity_product' => 'nullable',
            'new_price_product' => 'required|numeric',
            'new_status_product' => 'nullable|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'nullable|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tag2s,name_color',
            'image' => 'nullable|url',
        ]);
    
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
    
        $newBarcode = $request->input('new_barcode_product');
        $tables = [
            New_product::class,
            StagingProduct::class,
            StagingApprove::class,
        ];
    
        // Cek barcode
        foreach ($tables as $table) {
            if ($table::where('new_barcode_product', $newBarcode)->exists()) {
                return new ResponseResource(false, "The new barcode already exists", $newBarcode);
            }
        }
    
        DB::beginTransaction();
    
        try {
            $user = User::with('format_barcode')->find($userId);
    
            if (!$user || !$user->format_barcode_id) {
                return new ResponseResource(false, "User belum memiliki format barcode", $user->format_barcode_id);
            }
    
            UserScan::updateOrCreateDailyScan($userId, $user->format_barcode_id);
    
            $barcodeCustom = barcodeCustomUser($user->format_barcode->format, $userId);
    
            // Input Data
            $inputData = $request->only([
                'old_price_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'price_discount',
                'type',
                'image',
            ]);
    
            $inputData = array_merge($inputData, [
                'new_barcode_product' => $barcodeCustom,
                'new_quantity_product' => $inputData['new_quantity_product'] ?? 1,
                'code_document' => barcodeScan(),
                'new_status_product' => 'display',
                'user_id' => $userId,
                'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                'new_quality' => json_encode([
                    'lolos' => $request->input('condition') === 'lolos' ? 'lolos' : null,
                    'damaged' => $request->input('condition') === 'damaged' ? $request->input('description') : null,
                    'abnormal' => $request->input('condition') === 'abnormal' ? $request->input('description') : null,
                ]),
                'type' => 'type2',
            ]);
    
            if ($request->input('condition') !== 'lolos') {
                $inputData['new_category_product'] = null;
            }
    
            $inputData['new_discount'] = 0;
            $inputData['display_price'] = $inputData['new_price_product'];
    
            $newProduct = ProductInput::create($inputData);
    
            ProductScan::create([
                'user_id' => $userId,
                'product_name' => $inputData['new_name_product'],
                'product_price' => $inputData['old_price_product'],
                'image' => $request->input('image'),
            ]);

            $format = FormatBarcode::where('id', $user->format_barcode_id)->first();
            $format->update([
                'total_scan' => $format->total_scan + 1
            ]);
            DB::commit();
    
            return new ResponseResource(true, "Berhasil menambah data", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    

    /**
     * Display the specified resource.
     */
    public function show(ProductInput $productInput)
    {
        if ($productInput) {
            $category = Category::where('name_category', $productInput['new_category_product'])->first();
            $productInput['discount_category'] = $category ?$category->discount_category : null ;
            return new ResponseResource(true, "detail product input", $productInput);
        } else {
            return new ResponseResource(false, "id tidak ada", []);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductInput $productInput)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductInput $productInput)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'nullable',
            'old_barcode_product' => 'nullable',
            'new_barcode_product' => 'required',
            'new_name_product' => 'required',
            'new_quantity_product' => 'nullable',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
            'condition' => 'nullable',
            'new_category_product' => 'nullable',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',
            'new_discount' => 'nullable|numeric',
            'display_price' => 'required|numeric',
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

        $indonesiaTime = Carbon::now('Asia/Jakarta');
        $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

        if ($inputData['old_price_product'] > 120000) {
            $inputData['new_tag_product'] = null;
        }

        if ($request->input('old_price_product') <= 120000) {
            $tagwarna = ColorTag2::where('min_price_color', '<=', $request->input('old_price_product'))
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

        if ($productInput->new_category_product != null) {
            $inputData['new_barcode_product'] = $productInput->new_barcode_product;
        }

        $productInput->update($inputData);

        return new ResponseResource(true, "New Produk Berhasil di Update", $productInput);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductInput $productInput)
    {
        $productInput->delete();
        return new ResponseResource(true, "data berhasil di hapus", $productInput);
    }

    public function move_products(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();

        try {
            $product_filters = FilterProductInput::where('user_id', $userId)->get();
            
            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }

            $stagings = [];
            $productColor = [];
            $categorywrong = [];
            $tagcolorwrong = [];

            $product_filters->each(function ($product) use (&$stagings, &$productColor, &$categorywrong, &$tagcolorwrong) {
                if ($product->old_price_product >= 120000) {
                    if (is_null($product->new_category_product)) {
                        $categorywrong[] = $product->new_barcode_product;
                    } elseif ($product->new_tag_product !== null) {
                        $categorywrong = $product->new_barcode_product;
                    } else {
                        $stagings[] = [
                            'code_document' => $product->code_document,
                            'old_barcode_product' => $product->old_barcode_product,
                            'new_barcode_product' => $product->new_barcode_product,
                            'new_name_product' => $product->new_name_product,
                            'new_quantity_product' => $product->new_quantity_product,
                            'new_price_product' => $product->new_price_product,
                            'old_price_product' => $product->old_price_product,
                            'new_date_in_product' => $product->new_date_in_product,
                            'new_status_product' => $product->new_status_product,
                            'new_quality' => $product->new_quality,
                            'new_category_product' => $product->new_category_product,
                            'new_tag_product' => $product->new_tag_product,
                            'new_discount' => $product->new_discount,
                            'display_price' => $product->display_price,
                            'created_at' => now(),
                            'updated_at' => now(),
                            'type' => 'type2',
                            'user_id' => $product->user_id ?? null,
                        ];
                    }
                } elseif ($product->old_price_product <= 119999) {
                    if (is_null($product->new_tag_product) || !is_null($product->new_category_product)) {
                        $tagcolorwrong[] = $product;
                    } else {
                        $productColor[] = [
                            'code_document' => $product->code_document,
                            'old_barcode_product' => $product->old_barcode_product,
                            'new_barcode_product' => $product->new_barcode_product,
                            'new_name_product' => $product->new_name_product,
                            'new_quantity_product' => $product->new_quantity_product,
                            'new_price_product' => $product->new_price_product,
                            'old_price_product' => $product->old_price_product,
                            'new_date_in_product' => $product->new_date_in_product,
                            'new_status_product' => $product->new_status_product,
                            'new_quality' => $product->new_quality,
                            'new_category_product' => $product->new_category_product,
                            'new_tag_product' => $product->new_tag_product,
                            'new_discount' => $product->new_discount,
                            'display_price' => $product->display_price,
                            'created_at' => now(),
                            'updated_at' => now(),
                            'type' => 'type2',
                            'user_id' => $product->user_id ?? null,
                        ];
                    }
                }
            });

            if (!empty($categorywrong)) {
                return new ResponseResource(false, "Data kategori tidak sesuai", $categorywrong);
            }
            if (!empty($tagcolorwrong)) {
                return new ResponseResource(false, "Data tag color tidak sesuai", $tagcolorwrong);
            }

            FilterProductInput::where('user_id', $userId)->delete();
            StagingProduct::insert($stagings);
            New_product::insert($productColor);

            logUserAction($request, $request->user(), "product input", "product input" . $userId . " memindahkan produk ke approve");
            
             // Commit transaksi

            DB::commit();
            return new ResponseResource(true, "Product input approve berhasil dibuat", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan produk ke approve', 'error' => $e->getMessage()], 500);
        }
    }

    public function exportProductInput(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-Input.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductInputExport($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan asset dari public path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }
}
