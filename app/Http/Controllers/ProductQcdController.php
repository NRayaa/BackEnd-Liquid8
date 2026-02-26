<?php

namespace App\Http\Controllers;

use App\Models\BundleQcd;
use App\Models\FilterQcd;
use App\Models\ProductQcd;
use App\Models\StagingProduct;
use App\Models\New_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ResponseResource;
use Carbon\Carbon;

class ProductQcdController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $product_qcd = ProductQcd::latest()->paginate(100);
        return new ResponseResource(true, "list product bundle", $product_qcd);
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
        DB::beginTransaction();
        try {
            $product_filters = FilterQcd::all();
            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }

            $bundle = BundleQcd::create([
                'name_bundle' => $request->name_bundle,
                'total_price_bundle' => $request->total_price_bundle,
                'total_price_custom_bundle' => $request->total_price_custom_bundle,
                'total_product_bundle' => $request->total_product_bundle,
                'barcode_bundle' => barcodeQcd(),
                // 'category' => $request->category,
                // 'name_color' => $request->name_color,
            ]);

            $insertData = $product_filters->map(function ($product) use ($bundle, $userId) {
                return [
                    'bundle_qcd_id' => $bundle->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'pending_delete',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'type' => $product->type,
                    'user_id' => $userId
                ];
            })->toArray();

            ProductQcd::insert($insertData);

            FilterQcd::query()->delete();

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
    public function show(ProductQcd $productQcd)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductQcd $productQcd)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductQcd $productQcd)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            ProductQcd::where('bundle_qcd_id', $id)->delete();

            // $bundle = Bundle::findOrFail($id);
            // $bundle->delete();

            DB::commit();
            return new ResponseResource(true, "produk bundle  berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function moveToScrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer',
            'source'     => 'required|in:staging,display',
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
            } else {
                $product = New_product::find($productId);
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => "Produk tidak ditemukan di " . ucfirst($source),
                    'resource' => null
                ], 404);
            }

            $product->update([
                'new_status_product' => 'scrap_qcd'
            ]);

            DB::commit();

            return (new ResponseResource(true, "Berhasil menghapus produk QCD (Scrap)", $product))
                ->response()
                ->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal scrap produk: " . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => "Gagal menghapus produk: " . $e->getMessage(),
                'resource' => null
            ], 500);
        }
    }

    public function scrapAll(Request $request)
    {
        DB::beginTransaction();
        try {
            $stagingCount = StagingProduct::where('new_status_product', 'dump')
                ->update(['new_status_product' => 'scrap_qcd']);

            $displayCount = New_product::where('new_status_product', 'dump')
                ->update(['new_status_product' => 'scrap_qcd']);

            $totalDeleted = $stagingCount + $displayCount;

            DB::commit();

            if ($totalDeleted === 0) {
                return response()->json([
                    'status' => false,
                    'message' => "Tidak ada data dump untuk dihapus.",
                    'resource' => null
                ], 404);
            }

            return (new ResponseResource(true, "Berhasil menghapus semua ($totalDeleted) produk QCD", null))
                ->response()
                ->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal scrap all produk: " . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => "Gagal menghapus semua produk: " . $e->getMessage(),
                'resource' => null
            ], 500);
        }
    }
}
