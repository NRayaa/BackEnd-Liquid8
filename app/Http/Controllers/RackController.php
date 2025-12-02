<?php

namespace App\Http\Controllers;

use App\Models\Rack;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

class RackController extends Controller
{
    public function index(Request $request)
    {
        $query = Rack::query();

        if ($request->has('q')) {
            $query->where('name', 'like', '%' . $request->q . '%');
        }

        // filter by source (staging / display)
        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        $racks = $query->latest()->paginate(10);

        // Calculate totals specific to the source filter if present
        $totalRacks = Rack::when($request->has('source'), function ($q) use ($request) {
            $q->where('source', $request->source);
        })->count();

        $totalProductsInRacks = Rack::when($request->has('source'), function ($q) use ($request) {
            $q->where('source', $request->source);
        })->sum('total_data');

        return new ResponseResource(true, 'List Data Rak', [
            'racks' => $racks,
            'total_racks' => $totalRacks,
            'total_products_in_racks' => (int) $totalProductsInRacks
        ]);
    }

    // 2. Create Rak
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display',
            'category_id' => 'required|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $user_id = Auth::id();
            $category = Category::findOrFail($request->category_id);

            $categoryName = $category->name_category ?? $category->name;

            $prefixName = "{$user_id}-{$categoryName}";

            $latestRack = Rack::where('source', $request->source)
                ->where('name', 'LIKE', "{$prefixName}%")
                ->orderByRaw('LENGTH(name) DESC')
                ->orderBy('name', 'DESC')
                ->first();

            $nextNumber = 1;

            if ($latestRack) {
                $parts = explode(' ', $latestRack->name);
                $lastNumber = end($parts);

                if (is_numeric($lastNumber)) {
                    $nextNumber = (int) $lastNumber + 1;
                }
            }

            // generate name rack final
            $finalRackName = "{$prefixName} {$nextNumber}";

            $rack = Rack::create([
                'name' => $finalRackName,
                'source' => $request->source,
                'total_data' => 0
            ]);

            return new ResponseResource(true, 'Berhasil membuat rak: ' . $finalRackName, $rack);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Terjadi duplikasi nama rak, silakan coba lagi.',
                ], 422);
            }
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        // search rack
        $rack = Rack::find($id);

        if (!$rack) {
            return response()->json([
                'status' => false,
                'message' => 'Rak tidak ditemukan',
                'resource' => null
            ], 404);
        }

        $search = $request->q;
        $products = [];

        if ($rack->source === 'staging') {
            // search rak staging
            $query = $rack->stagingProducts();

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $products = $query->latest()->get();
        } elseif ($rack->source === 'display') {
            // search rak display
            $query = $rack->newProducts();

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $products = $query->latest()->get();
        }

        return new ResponseResource(true, 'Detail Data Rak dan Produk', [
            'rack_info' => $rack,
            'products'  => $products
        ]);
    }

    public function update(Request $request, $id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return response()->json([
                'status' => false,
                'message' => 'Rak tidak ditemukan',
                'resource' => null
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $user_id = Auth::id();
            $category = Category::findOrFail($request->category_id);

            $categoryName = $category->name_category ?? $category->name;

            $prefixName = "{$user_id}-{$categoryName}";

            $latestRack = Rack::where('source', $rack->source)
                ->where('name', 'LIKE', "{$prefixName}%")
                ->orderByRaw('LENGTH(name) DESC')
                ->orderBy('name', 'DESC')
                ->first();

            $nextNumber = 1;

            if ($latestRack) {
                $parts = explode(' ', $latestRack->name);
                $lastNumber = end($parts);

                if (is_numeric($lastNumber)) {
                    $nextNumber = (int) $lastNumber + 1;
                }
            }

            $finalRackName = "{$prefixName} {$nextNumber}";

            DB::beginTransaction();

            $rack->update([
                'name' => $finalRackName
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil memperbarui nama rak: ' . $finalRackName, $rack);
        } catch (QueryException $e) {
            DB::rollback();
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Terjadi duplikasi saat generate nama, silakan coba lagi.',
                ], 422);
            }
            return response()->json(['status' => false, 'message' => 'Gagal update: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'status' => false,
                'message' => 'Gagal update: ' . $e->getMessage(),
                'resource' => null
            ], 500);
        }
    }

    // delete rack
    public function destroy($id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return new ResponseResource(false, 'Rak tidak ditemukan', null);
        }
        if ($rack->total_data > 0) {
            if (in_array($rack->source, ['staging', 'display'])) {
                $model = $rack->source === 'staging' ? StagingProduct::class : New_product::class;
                $model::where('rack_id', $rack->id)->update(['rack_id' => null]);
            }
        }
        $rack->delete();

        return new ResponseResource(true, 'Berhasil hapus rak', null);
    }

    // add product to rack staging
    public function addStagingProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'product_id' => 'required|exists:staging_products,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $product = StagingProduct::find($request->product_id);

        if ($rack->source !== 'staging') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: ID Rak yang Anda pilih adalah rak tipe Display. Gunakan endpoint khusus display.',
                'resource' => null
            ], 422);
        }

        // cek apakah produk sudah ada di rak lain
        if ($product->rack_id != null) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk Staging ini sudah tersimpan di rak lain.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();
            $product->update(['rack_id' => $rack->id]);
            $this->recalculateRackTotals($rack);
            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak Staging: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    // add product to rack display
    public function addDisplayProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'product_id' => 'required|exists:new_products,id',
            'new_barcode_product' => 'required|exists:new_products,new_barcode_product',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $product = New_product::find($request->product_id);

        if ($rack->source !== 'display') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: ID Rak yang Anda pilih adalah rak tipe Staging. Gunakan endpoint khusus staging.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != null) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk Display ini sudah tersimpan di rak lain.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();
            $product->update(['rack_id' => $rack->id]);
            $this->recalculateRackTotals($rack);
            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak Display: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    private function recalculateRackTotals($rack)
    {
        if ($rack->source == 'staging') {
            $products = $rack->stagingProducts();
            $totalData = $products->count();
            $totalNewPrice = $products->sum('new_price_product');
            $totalOldPrice = $products->sum('old_price_product');
            $totalDisplayPrice = $products->sum('display_price');

            $rack->update([
                'total_data' => $totalData,
                'total_new_price_product' => $totalNewPrice,
                'total_old_price_product' => $totalOldPrice,
                'total_display_price_product' => $totalDisplayPrice,
            ]);
        } else {
            // Display / New Product
            $products = $rack->newProducts();
            $totalData = $products->count();
            $totalNewPrice = $products->sum('new_price_product');
            $totalOldPrice = $products->sum('old_price_product');
            $totalDisplayPrice = $products->sum('display_price');

            $rack->update([
                'total_data' => $totalData,
                'total_new_price_product' => $totalNewPrice,
                'total_old_price_product' => $totalOldPrice,
                'total_display_price_product' => $totalDisplayPrice,
            ]);
        }
    }

    public function removeStagingProduct($rack_id, $product_id)
    {
        $rack = Rack::find($rack_id);
        $product = StagingProduct::find($product_id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan', 'resource' => null], 404);
        }
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Produk Staging tidak ditemukan', 'resource' => null], 404);
        }

        if ($rack->source !== 'staging') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ini bukan tipe Staging.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != $rack->id) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk ini tidak berada di rak tersebut.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Keluarkan dari rak
            $product->update(['rack_id' => null]);

            // Hitung ulang
            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menghapus produk staging dari rak', $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    public function removeDisplayProduct($rack_id, $product_id)
    {
        $rack = Rack::find($rack_id);
        $product = New_product::find($product_id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan', 'resource' => null], 404);
        }
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Produk Display tidak ditemukan', 'resource' => null], 404);
        }

        if ($rack->source !== 'display') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ini bukan tipe Display.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != $rack->id) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk ini tidak berada di rak tersebut.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Keluarkan dari rak
            $product->update(['rack_id' => null]);

            // Hitung ulang
            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menghapus produk display dari rak', $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    public function listStagingProducts(Request $request)
    {
        $search = $request->q;

        try {
            $query = StagingProduct::query()
                ->select(
                    'id',
                    'new_name_product',
                    'new_barcode_product',
                    'old_barcode_product',
                    'code_document',
                )
                ->whereNull('rack_id');


            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $stagingProducts = $query->latest()->paginate(50);

            return new ResponseResource(true, 'List Produk Staging Belum Masuk Rak (Unassigned)', [
                'products' => $stagingProducts,
                'count' => $stagingProducts->total(),
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function listDisplayProducts(Request $request)
    {
        $search = $request->q;

        try {
            $query = New_product::query()
                ->select(
                    'id',
                    'new_name_product',
                    'new_barcode_product',
                    'old_barcode_product',
                    'code_document',
                )
                ->whereNull('rack_id');


            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $displayProducts = $query->latest()->paginate(50);

            return new ResponseResource(true, 'List Produk Display Belum Masuk Rak (Unassigned)', [
                'products' => $displayProducts,
                'count' => $displayProducts->total(),
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function addProductByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'barcode' => 'required',
            'source'  => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $barcode = $request->barcode;
        $source = $request->source;

        if ($rack->source != $source) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Source rak (' . $rack->source . ') tidak cocok dengan source input (' . $source . ').'
            ], 422);
        }

        try {
            DB::beginTransaction();

            if ($source === 'staging') {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Staging tidak ditemukan dengan barcode: ' . $barcode], 404);
                }

                if ($product->rack_id != null) {
                    $currentRack = Rack::find($product->rack_id);
                    return response()->json([
                        'status' => false,
                        'message' => 'Produk sudah berada di rak lain: ' . ($currentRack ? $currentRack->name : 'Unknown')
                    ], 422);
                }

                $product->update(['rack_id' => $rack->id]);
            } else {
                $product = New_product::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Display tidak ditemukan dengan barcode: ' . $barcode], 404);
                }

                if ($product->rack_id != null) {
                    $currentRack = Rack::find($product->rack_id);
                    return response()->json([
                        'status' => false,
                        'message' => 'Produk sudah berada di rak lain: ' . ($currentRack ? $currentRack->name : 'Unknown')
                    ], 422);
                }

                $product->update(['rack_id' => $rack->id]);
            }

            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak ' . $rack->name, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // move all product staging in rack staging to display
    public function moveAllProductsInRackToDisplay($rack_id)
    {
        $rack = Rack::find($rack_id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan'], 404);
        }

        if ($rack->source !== 'staging') {
            return response()->json(['status' => false, 'message' => 'Hanya bisa memindahkan dari Rak Staging.'], 422);
        }

        $query = $rack->stagingProducts();

        if ($query->count() === 0) {
            return response()->json(['status' => false, 'message' => 'Rak ini kosong.'], 422);
        }

        try {
            DB::beginTransaction();

            $query->chunkById(100, function ($products) {
                $dataToInsert = [];
                $idsToDelete = [];
                $now = now();

                foreach ($products as $stagingProduct) {
                    $dataToInsert[] = [
                        'code_document'        => $stagingProduct->code_document,
                        'old_barcode_product'  => $stagingProduct->old_barcode_product,
                        'new_barcode_product'  => $stagingProduct->new_barcode_product,
                        'new_name_product'     => $stagingProduct->new_name_product,
                        'new_quantity_product' => $stagingProduct->new_quantity_product,
                        'new_price_product'    => $stagingProduct->new_price_product,
                        'old_price_product'    => $stagingProduct->old_price_product,
                        'new_date_in_product'  => $stagingProduct->new_date_in_product,
                        'new_status_product'   => $stagingProduct->new_status_product,
                        'new_quality'          => $stagingProduct->new_quality,
                        'new_category_product' => $stagingProduct->new_category_product,
                        'new_tag_product'      => $stagingProduct->new_tag_product,
                        'display_price'        => $stagingProduct->display_price,
                        'new_discount'         => $stagingProduct->new_discount,
                        'type'                 => $stagingProduct->type,
                        'user_id'              => $stagingProduct->user_id,
                        'is_so'                => $stagingProduct->is_so,
                        'user_so'              => $stagingProduct->user_so,
                        'actual_old_price_product' => $stagingProduct->actual_old_price_product,
                        'actual_new_quality'   => $stagingProduct->actual_new_quality,
                        'rack_id'              => null,
                        'created_at'           => $now,
                        'updated_at'           => $now,

                    ];

                    $idsToDelete[] = $stagingProduct->id;
                }

                if (!empty($dataToInsert)) {
                    New_product::insert($dataToInsert);
                }

                if (!empty($idsToDelete)) {
                    $products->first()->newQuery()->whereIn('id', $idsToDelete)->delete();
                }
            });

            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, "Berhasil memindahkan produk dari Rak " . $rack->name . " ke Display.", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => 'Gagal memindahkan produk: ' . $e->getMessage()], 500);
        }
    }
}
