<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\ColorTag2;
use App\Models\New_product;
use App\Models\ProductInput;
use Illuminate\Http\Request;
use App\Models\Product_Bundle;
use App\Models\Product_Filter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Exists;
use App\Http\Resources\ResponseResource;
use App\Models\FormatBarcode;
use Illuminate\Support\Facades\Validator;

class ProductBundleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $product_bundles = Product_Bundle::latest()->paginate(100);
        return new ResponseResource(true, "list product bundle", $product_bundles);
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
            $product_filters = Product_Filter::where('user_id', $userId)->get();
            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }
            $validator = Validator::make($request->all(), [
                'name_bundle' => 'required',
                'total_price_bundle' => 'nullable',
                'total_price_custom_bundle' => 'nullable',
                'total_product_bundle' => 'nullable',
                'category' => 'nullable|exists:categories,name_category',
                'name_color' => 'nullable|exists:color_tags,name_color'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $bundle = Bundle::create([
                'name_bundle' => $request->name_bundle,
                'total_price_bundle' => $request->total_price_bundle ?? 0,
                'total_price_custom_bundle' => $request->total_price_custom_bundle ?? 0,
                'total_product_bundle' => $request->total_product_bundle ?? 0,
                'barcode_bundle' => barcodeBundle(),
                'category' => $request->category ?? null,
                'name_color' => $request->name_color ?? null,
            ]);

            $insertData = $product_filters->map(function ($product) use ($bundle) {
                return [
                    'bundle_id' => $bundle->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'bundle',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'type' => $product->type,
                    'user_id' => $product->user_id ?? null
                ];
            })->toArray();

            Product_Bundle::insert($insertData);

            Product_Filter::where('user_id', $userId)->delete();

            logUserAction($request, $request->user(), "storage/moving_product/create_bundle", "Create bundle " . $bundle->name_bundle . "->" . $userId);

             // Commit transaksi

            DB::commit();
            return new ResponseResource(true, "Bundle berhasil dibuat", $bundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bundle', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(Product_Bundle $product_Bundle)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product_Bundle $product_Bundle)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product_Bundle $product_Bundle)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product_Bundle $productBundle)
    {
        DB::beginTransaction();
        try {
            New_product::create([
                'code_document' => $productBundle->code_document,
                'old_barcode_product' => $productBundle->old_barcode_product,
                'new_barcode_product' => $productBundle->new_barcode_product,
                'new_name_product' => $productBundle->new_name_product,
                'new_quantity_product' => $productBundle->new_quantity_product,
                'old_price_product' => $productBundle->old_price_product,
                'new_price_product' => $productBundle->new_price_product,
                'new_date_in_product' => $productBundle->new_date_in_product,
                'new_status_product' => 'display',
                'new_quality' => $productBundle->new_quality,
                'new_category_product' => $productBundle->new_category_product,
                'new_tag_product' => $productBundle->new_tag_product,
                'new_discount' => $productBundle->new_discount,
                'display_price' => $productBundle->display_price,
                'created_at' => $productBundle->created_at,
                'updated_at' => $productBundle->updated_at,
                'type' => $productBundle->type,
                'user_id' => $productBundle->user_id ??null
            ]);

            $bundle = Bundle::findOrFail($productBundle->bundle_id);
            $productBundle->delete();
            $remainingProductBundles = Product_Bundle::where('bundle_id', $productBundle->bundle_id)->count();

            if ($remainingProductBundles == 0) {
                $bundle->delete();
            } else {
                //calculate
                $old_price_product = $productBundle->old_price_product;
                $totalPrice = $bundle->total_price_bundle - $old_price_product;

                if ($totalPrice >= 100000) {
                    $discount = Category::where('name_category', $bundle->category)->pluck('discount_category')->first();
                    if (!empty($discount)) {
                        $priceDiscount = $totalPrice * ($discount / 100);

                        // Mengupdate harga bundle dan produk
                        $bundle->update([
                            'total_price_bundle' => $totalPrice,
                            'total_price_custom_bundle' => $priceDiscount,
                            'total_product_bundle' => $bundle->total_product_bundle - 1,
                            'name_color' => null
                        ]);
                    }
                } else if ($totalPrice < 100000) {
                    $tagwarna = Color_tag::where('min_price_color', '<=',  $totalPrice)
                        ->where('max_price_color', '>=', $totalPrice)
                        ->select('fixed_price_color', 'name_color', 'hexa_code_color')->first();
                    $bundle->update([
                        'total_price_bundle' => $totalPrice,
                        'total_price_custom_bundle' => $tagwarna->fixed_price_color,
                        'total_product_bundle' => $bundle->total_product_bundle - 1,
                        'name_color' => $tagwarna->name_color,
                        'category' => null
                    ]);
                }
            }


            DB::commit();
            return new ResponseResource(true, "Produk bundle berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }


    public function addProductBundle(New_product $new_product, Bundle $bundle)
    {
        DB::beginTransaction();
        try {

            $productBundle = Product_Bundle::create([
                'bundle_id' => $bundle->id,
                'code_document' => $new_product->code_document,
                'old_barcode_product' => $new_product->old_barcode_product,
                'new_barcode_product' => $new_product->new_barcode_product,
                'new_name_product' => $new_product->new_name_product,
                'new_quantity_product' => $new_product->new_quantity_product,
                'new_price_product' => $new_product->new_price_product,
                'old_price_product' => $new_product->old_price_product,
                'new_date_in_product' => $new_product->new_date_in_product,
                'new_status_product' => 'bundle',
                'new_quality' => $new_product->new_quality,
                'new_category_product' => $new_product->new_category_product,
                'new_tag_product' => $new_product->new_tag_product,
                'new_discount' => $new_product->new_discount,
                'display_price' => $new_product->display_price,
                'type' => $new_product->type,
                'user_id' => $new_product->user_id ?? null
            ]);

            //calculate
            $old_price_product = $new_product->old_price_product;
            $totalPrice = $bundle->total_price_bundle + $old_price_product;

            if ($totalPrice >= 100000) {
                
                $discount = Category::where('name_category', $bundle->category)->pluck('discount_category')->first();
                if (!empty($discount)) {
                    $priceDiscount = $totalPrice * ($discount / 100);

                    // Mengupdate harga bundle dan produk
                    $bundle->update([
                        'total_price_bundle' => $totalPrice,
                        'total_price_custom_bundle' => $priceDiscount,
                        'total_product_bundle' => $bundle->total_product_bundle + 1,
                        'name_color' => null
                    ]);
                }else {
                    $bundle->update([
                        'total_price_bundle' => $totalPrice,
                        'total_price_custom_bundle' => $totalPrice,
                        'total_product_bundle' => $bundle->total_product_bundle + 1,
                        'name_color' => null
                    ]);

                }
            } else if ($totalPrice < 100000) {
                $tagwarna = Color_tag::where('min_price_color', '<=',  $totalPrice)
                    ->where('max_price_color', '>=', $totalPrice)
                    ->select('fixed_price_color', 'name_color', 'hexa_code_color')->first();
                $bundle->update([
                    'total_price_bundle' => $totalPrice,
                    'total_price_custom_bundle' => $tagwarna->fixed_price_color,
                    'total_product_bundle' => $bundle->total_product_bundle + 1,
                    'name_color' => $tagwarna->name_color,
                    'category' => null
                ]);
            }

            $new_product->delete();

            DB::commit();
            return new ResponseResource(true, "Product bundle berhasil di tambahkan", $productBundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bundle', 'error' => $e->getMessage()], 500);
        }
    }

    //mtc
    public function createBundleScan(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();

        try {

            $validator = Validator::make($request->all(), [
                'name_bundle' => 'required',
                'total_price_bundle' => 'nullable',
                'total_price_custom_bundle' => 'nullable',
                'total_product_bundle' => 'nullable',
                'category' => 'nullable|exists:categories,name_category',
                'name_color' => 'nullable|exists:color_tag2s,name_color',
                'ids' => 'nullable|array|min:1', 
                'ids.*' => 'integer|exists:product_inputs,id'
                
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // $product_filters = Product_Filter::where('user_id', $userId)->get();

            $user = User::findOrFail($userId);
            $formatBarcode = FormatBarcode::findOrFail($user->format_barcode_id);

            $bundle = Bundle::create([
                'name_bundle' => $request->name_bundle,
                'total_price_bundle' => $request->total_price_bundle ?? 0,
                'total_price_custom_bundle' => $request->total_price_custom_bundle ?? 0,
                'total_product_bundle' => $request->total_product_bundle ?? 0,
                'barcode_bundle' => barcodeBundleScan($formatBarcode->format),
                'category' => $request->category ?? null,
                'name_color' => $request->name_color ?? null,
                'type' => 'type2'
            ]);

             $ids = $request->input('ids');
    
             $products = ProductInput::whereIn('id', $ids)->get();
     
             if ($products->isEmpty()) {
                 return response()->json(['message' => 'No products found for the given IDs'], 404);
             }
     
             $productFilters = [];
     
             foreach ($products as $product) {
                 // Buat entri di Product_Bundle
                 $productBundle = Product_Bundle::create([
                     'bundle_id' => $bundle->id,
                     'code_document' => $product->code_document,
                     'old_barcode_product' => $product->old_barcode_product,
                     'new_barcode_product' => $product->new_barcode_product,
                     'new_name_product' => $product->new_name_product,
                     'new_quantity_product' => $product->new_quantity_product,
                     'new_price_product' => $product->new_price_product,
                     'old_price_product' => $product->old_price_product,
                     'new_date_in_product' => $product->new_date_in_product,
                     'new_status_product' => 'bundle',
                     'new_quality' => $product->new_quality,
                     'new_category_product' => $product->new_category_product,
                     'new_tag_product' => $product->new_tag_product,
                     'new_discount' => $product->new_discount,
                     'display_price' => $product->display_price,
                     'type' => $product->type,
                     'image' => $product->image,
                     'user_id' => $product->user_id ?? null
                 ]);
    
                 $productFilters[] = $productBundle;
     
                 // Hapus produk asli
                 $product->delete();
             }

            logUserAction($request, $request->user(), "storage/moving_product/create_bundle", "Create bundle scans " . $bundle->name_bundle . "->" . $userId);

            // Commit transaksi
            DB::commit();
            return new ResponseResource(true, "Bundle berhasil dibuat", $bundle);
        } catch (\Exception $e) {
            // Rollback transaksi jika terjadi error
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function addFilterScan(Request $request, $id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
    
        try {
            // Validasi request menggunakan Laravel Validator
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array|min:1', // Pastikan 'ids' adalah array yang tidak kosong
                'ids.*' => 'integer|exists:product_inputs,id' // Setiap elemen harus integer dan ada di tabel product_inputs
            ]);
    
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }
            
            // Ambil array IDs yang telah divalidasi
            $ids = $request->input('ids');
    
            // Ambil data produk berdasarkan array IDs
            $products = ProductInput::whereIn('id', $ids)->get();
    
            if ($products->isEmpty()) {
                return response()->json(['message' => 'No products found for the given IDs'], 404);
            }
    
            $productFilters = [];
    
            foreach ($products as $product) {
                // Buat entri di Product_Bundle
                $productBundle = Product_Bundle::create([
                    'bundle_id' => $id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'bundle',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'type' => $product->type,
                    'user_id' => $userId, 
                ]);
    
                $productFilters[] = $productBundle;
    
                // Hapus produk asli
                $product->delete();
            }
    
            DB::commit();
    
            return new ResponseResource(true, "Successfully added products to the bundle list", $productFilters);
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    

    public function addProductInBundle(Request $request, Bundle $bundle)
    {
        $userId = auth()->id();
        DB::beginTransaction();
        try {
            // Validasi input
            $validator = Validator::make($request->all(), [
                'productId' => 'required|array',
                'productId.*' => 'integer|exists:product_inputs,id'
            ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }    
            $productIds = $request->input('productId');
    
            // Hitung total harga dan total produk dalam bundle saat ini
            $totalPrice = Product_Bundle::where('bundle_id', $bundle->id)
                ->sum('old_price_product');
            $totalProduct = Product_Bundle::where('bundle_id', $bundle->id)->count();
            $totalIn = 0;
    
            // Proses data menggunakan chunk
            ProductInput::whereIn('id', $productIds) // Ganti 'where' dengan 'whereIn'
                ->chunk(100, function ($products) use ($bundle, &$totalPrice, &$totalProduct, &$totalIn, $userId) {
                    foreach ($products as $product) {
                        // Tambahkan produk ke bundle
                        Product_Bundle::create([
                            'bundle_id' => $bundle->id,
                            'code_document' => $product->code_document,
                            'old_barcode_product' => $product->old_barcode_product,
                            'new_barcode_product' => $product->new_barcode_product,
                            'new_name_product' => $product->new_name_product,
                            'new_quantity_product' => $product->new_quantity_product,
                            'new_price_product' => $product->new_price_product,
                            'old_price_product' => $product->old_price_product,
                            'new_date_in_product' => $product->new_date_in_product,
                            'new_status_product' => 'bundle',
                            'new_quality' => $product->new_quality,
                            'new_category_product' => $product->new_category_product,
                            'new_tag_product' => $product->new_tag_product,
                            'new_discount' => $product->new_discount,
                            'display_price' => $product->display_price,
                            'type' => $product->type,
                            'user_id' => $userId
                        ]);
    
                        // Perbarui total harga dan total produk
                        $totalPrice += $product->old_price_product;
                        $totalProduct++;
                        $totalIn++;
    
                        // Hapus produk dari tabel asal
                        $product->delete();
                    }
    
                    // Hitung ulang bundle jika perlu
                    if ($totalPrice >= 120000) {
                        $discount = Category::where('name_category', $bundle->category)
                            ->pluck('discount_category')->first();
    
                        if (!empty($discount)) {
                            $priceDiscount = $totalPrice * ($discount / 100);
    
                            $bundle->update([
                                'total_price_bundle' => $totalPrice,
                                'total_price_custom_bundle' => $priceDiscount,
                                'total_product_bundle' => $totalProduct,
                                'name_color' => null,
                            ]);
                        }
                    } else {
                        $tagwarna = ColorTag2::where('min_price_color', '<=', $totalPrice)
                            ->where('max_price_color', '>=', $totalPrice)
                            ->select('fixed_price_color', 'name_color', 'hexa_code_color')->first();
    
                        if ($tagwarna) {
                            $bundle->update([
                                'total_price_bundle' => $totalPrice,
                                'total_price_custom_bundle' => $tagwarna->fixed_price_color,
                                'total_product_bundle' => $totalProduct,
                                'name_color' => $tagwarna->name_color,
                                'category' => null,
                            ]);
                        }
                    }
                });
    
            // Commit transaksi
            DB::commit();
    
            return new ResponseResource(true, "Product bundle berhasil di tambahkan", $totalIn);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
    
            return response()->json([
                'success' => false,
                'message' => 'Gagal memindahkan product ke bundle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    

    public function destroyProductBundle(Request $request, Bundle $bundle)
    {
        $userId = auth()->id();
        DB::beginTransaction(); // Hapus duplikasi transaksi
        try {
            // Validasi input untuk produk yang ada dalam bundle
            $validator = Validator::make($request->all(), [
                'productId' => 'required|array',
                'productId.*' => 'integer|exists:product__bundles,id' 
            ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
    
            $productIds = $request->input('productId');
    
            // Inisialisasi total harga dan total produk bundle
            $totalPrice = Product_Bundle::where('bundle_id', $bundle->id)->sum('old_price_product');
            $totalProduct = Product_Bundle::where('bundle_id', $bundle->id)->count();
            $totalOut = 0;
            $productBundle = Product_Bundle::where('id', 4)->get();
            // Proses data menggunakan chunk
            Product_Bundle::whereIn('id', $productIds)
                ->chunk(100, function ($products) use ($bundle, &$totalPrice, &$totalProduct, &$totalOut, $userId) {
                    foreach ($products as $product) {
                        ProductInput::create([
                            'code_document' => $product->code_document,
                            'old_barcode_product' => $product->old_barcode_product,
                            'new_barcode_product' => $product->new_barcode_product,
                            'new_name_product' => $product->new_name_product,
                            'new_quantity_product' => $product->new_quantity_product,
                            'new_price_product' => $product->new_price_product,
                            'old_price_product' => $product->old_price_product,
                            'new_date_in_product' => $product->new_date_in_product,
                            'new_status_product' => 'bundle',
                            'new_quality' => $product->new_quality,
                            'new_category_product' => $product->new_category_product,
                            'new_tag_product' => $product->new_tag_product,
                            'new_discount' => $product->new_discount,
                            'display_price' => $product->display_price,
                            'type' => $product->type,
                            'user_id' => $userId
                        ]);
    
                        $totalPrice -= $product->old_price_product;
                        $totalProduct--;
                        $totalOut++;
    
                        $product->delete();
                    }
                });
    
            // Periksa apakah bundle harus dihapus
            if ($totalProduct <= 0) {
                $bundle->delete();
            } else {
                // Update bundle jika masih ada produk
                if ($totalPrice >= 120000) {
                    $discount = Category::where('name_category', $bundle->category)
                        ->pluck('discount_category')->first();
    
                    if (!empty($discount)) {
                        $priceDiscount = $totalPrice * ($discount / 100);
                        $bundle->update([
                            'total_price_bundle' => $totalPrice,
                            'total_price_custom_bundle' => $priceDiscount,
                            'total_product_bundle' => $totalProduct,
                            'name_color' => null,
                        ]);
                    }
                } else {
                    $tagwarna = ColorTag2::where('min_price_color', '<=', $totalPrice)
                        ->where('max_price_color', '>=', $totalPrice)
                        ->select('fixed_price_color', 'name_color', 'hexa_code_color')->first();
    
                    if ($tagwarna) {
                        $bundle->update([
                            'total_price_bundle' => $totalPrice,
                            'total_price_custom_bundle' => $tagwarna->fixed_price_color,
                            'total_product_bundle' => $totalProduct,
                            'name_color' => $tagwarna->name_color,
                            'category' => null,
                        ]);
                    }
                }
            }
    
            DB::commit();
    
            return new ResponseResource(true, "Produk bundle berhasil dihapus",$totalOut);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus bundle: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus bundle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
}
