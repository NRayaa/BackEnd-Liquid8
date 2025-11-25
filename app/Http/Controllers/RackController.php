<?php

namespace App\Http\Controllers;

use App\Models\Rack;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

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

        $totalRacks = Rack::count();
        $totalProductsInRacks = Rack::sum('total_data');

        return new ResponseResource(true, 'List Data Rak', [
            'racks' => $racks,
            'total_racks' => $totalRacks,
            'total_products_in_racks' => $totalProductsInRacks
        ]);
    }

    public function getTotalRacks()
    {
        $total = Rack::count();
        return new ResponseResource(true, 'Total Jumlah Rak', $total);
    }

    public function getTotalProducts()
    {
        $total = Rack::sum('total_data');
        return new ResponseResource(true, 'Total Seluruh Produk dalam Rak', $total);
    }

    // 2. Create Rak
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display',
            'name' => [
                'required',
                // valdiasi nama harus unik jika source-nya sama
                Rule::unique('racks')->where(function ($query) use ($request) {
                    return $query->where('source', $request->source);
                }),
            ],
        ], [
            'name.unique' => 'Nama rak sudah ada untuk tipe ' . $request->source,
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $rack = Rack::create([
                'name' => $request->name,
                'source' => $request->source,
                'total_data' => 0
            ]);

            return new ResponseResource(true, 'Berhasil membuat rak', $rack);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validasi Gagal: Nama rak sudah digunakan untuk tipe ini.',
                    'errors' => ['name' => ['Nama rak sudah ada untuk tipe ' . $request->source]]
                ], 422);
            }
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

    // update rack name
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
            'name' => [
                'required',
                'string',
                Rule::unique('racks')->where(function ($query) use ($rack) {
                    return $query->where('source', $rack->source);
                })->ignore($rack->id),
            ],
        ], [
            'name.unique' => 'Nama rak sudah ada untuk tipe ' . $rack->source,
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $rack->update([
                'name' => $request->name
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil mengubah nama rak', $rack);
        } catch (QueryException $e) {
            DB::rollback();
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validasi Gagal: Nama rak sudah digunakan.',
                    'errors' => ['name' => ['Nama rak sudah ada.']]
                ], 422);
            }
            return response()->json(['status' => false, 'message' => 'Gagal update: ' . $e->getMessage(), 'resource' => null], 500);
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
            return new ResponseResource(false, 'Gagal hapus. Rak masih berisi produk.', null);
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

    public function moveStagingToDisplay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staging_product_id' => 'required|exists:staging_products,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $stagingProduct = StagingProduct::find($request->staging_product_id);

        if (!$stagingProduct->rack_id) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk ini belum masuk ke rak staging manapun (rack_id null).'
            ], 422);
        }

        $sourceRack = Rack::find($stagingProduct->rack_id);

        try {
            DB::beginTransaction();

            $newProduct = New_product::create([
                'code_document' => $stagingProduct->code_document,
                'old_barcode_product' => $stagingProduct->old_barcode_product,
                'new_barcode_product' => $stagingProduct->new_barcode_product,
                'new_name_product' => $stagingProduct->new_name_product,
                'new_quantity_product' => $stagingProduct->new_quantity_product,
                'new_price_product' => $stagingProduct->new_price_product,
                'old_price_product' => $stagingProduct->old_price_product,
                'new_date_in_product' => $stagingProduct->new_date_in_product,
                'new_status_product' => 'display',
                'new_quality' => $stagingProduct->new_quality,
                'new_category_product' => $stagingProduct->new_category_product,
                'new_tag_product' => $stagingProduct->new_tag_product,
                'display_price' => $stagingProduct->display_price,
                'rack_id' => null,
            ]);

            $stagingProduct->delete();

            if ($sourceRack) {
                $this->recalculateRackTotals($sourceRack);
            }

            DB::commit();

            return new ResponseResource(true, 'Berhasil memindahkan produk dari Rak Staging ke Tabel Display (Belum masuk Rak)', $newProduct);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => 'Gagal memindahkan produk: ' . $e->getMessage()], 500);
        }
    }
}