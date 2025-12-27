<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Repair;
use App\Models\Notification;
use App\Models\RepairFilter;
use Illuminate\Http\Request;
use App\Models\RepairProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use Illuminate\Support\Facades\Validator;

class RepairProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $product_repairs = RepairProduct::latest()->paginate(20);
        return new ResponseResource(true, "list product repair", $product_repairs);
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
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $productFilters = RepairFilter::where('user_id', $userId)->get();

            if ($productFilters->isEmpty()) {
                return new ResponseResource(false, "Keranjang filter kosong. Masukkan produk dulu.", null);
            }

            $validator = Validator::make($request->all(), [
                'repair_name' => 'required|unique:repairs,repair_name',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, $validator->errors()->first(), null))
                    ->response()->setStatusCode(422);
            }

            $totalProducts = $productFilters->sum('new_quantity_product'); 
            
            $totalPrice = $productFilters->sum('new_price_product'); 
            $totalCustomPrice = $productFilters->sum('old_price_product');

            $repair = Repair::create([
                'user_id' => $userId,
                'repair_name' => $request->repair_name,
                'barcode' => barcodeRepair(),
                'total_products' => $totalProducts,
                'total_price' => $totalPrice,
                'total_custom_price' => $totalCustomPrice,
                'product_status' => 'not sale',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $insertData = $productFilters->map(function ($filterItem) use ($repair, $userId) {
                return [
                    'repair_id' => $repair->id,
                    'user_id' => $userId,
                    'code_document' => $filterItem->code_document,
                    'old_barcode_product' => $filterItem->old_barcode_product,
                    'new_barcode_product' => $filterItem->new_barcode_product,
                    'new_name_product' => $filterItem->new_name_product,
                    'new_quantity_product' => $filterItem->new_quantity_product,
                    'new_price_product' => $filterItem->new_price_product,
                    'old_price_product' => $filterItem->old_price_product,
                    'display_price' => $filterItem->display_price,
                    'new_category_product' => $filterItem->new_category_product,
                    'new_tag_product' => $filterItem->new_tag_product,
                    'new_status_product' => 'repair',
                    'new_quality' => $filterItem->new_quality,
                    'new_date_in_product' => $filterItem->new_date_in_product,
                    'new_discount' => $filterItem->new_discount,
                    'type' => $filterItem->type,
                    'actual_old_price_product' => $filterItem->old_price_product,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            RepairProduct::insert($insertData);

            RepairFilter::where('user_id', $userId)->delete();

            $notification = Notification::create([
                'user_id' => $userId,
                'notification_name' => 'Butuh Approvement untuk Repair: ' . $request->repair_name,
                'role' => 'Spv',
                'read_at' => now('Asia/Jakarta'),
                'repair_id' => $repair->id,
            ]);

            DB::commit();
            return new ResponseResource(true, "Repair berhasil dibuat dan dikalkulasi.", [
                'repair_header' => $repair,
                'total_items_moved' => $totalProducts
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat repair',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store2(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $productFilters = RepairFilter::where('user_id', $userId)->get();

            if ($productFilters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $productFilters);
            }

            $validator = Validator::make($request->all(), [
                'repair_name' => 'required|unique:repairs,repair_name',
                'barcode' => 'nullable|unique:repairs,barcode',
            ]);

            if ($validator->fails()) {
                // Return a response with the validation errors and a 422 status code
                $response = new ResponseResource(false, $validator->errors()->first(), null);
                return $response->response()->setStatusCode(422);
            }

            $repair = Repair::create([
                'user_id' => $userId,
                'repair_name' => $request->repair_name,
                'total_price' => $request->total_price,
                'total_custom_price' => $request->total_custom_price,
                'total_products' => $request->total_products,
                'product_status' => 'not sale',
                'barcode' => barcodeRepair(),
            ]);

            $insertData = $productFilters->map(function ($product) use ($repair, $userId) {
                return [
                    'repair_id' => $repair->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'repair',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'type' => $product->type,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            RepairProduct::insert($insertData);

            RepairFilter::where('user_id', $userId)->delete();

            $notification = Notification::create([
                'user_id' => $userId,
                'notification_name' => 'Butuh Approvement untuk Repair',
                'role' => 'Spv',
                'read_at' => Carbon::now('Asia/Jakarta'),
                'riwayat_check_id' => null,
                'repair_id' => $repair->id,
            ]);

            DB::commit();
            return new ResponseResource(true, "Repair berhasil dibuat", [$repair, $notification]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Gagal membuat repair: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan produk ke repair', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(RepairProduct $repairProduct)
    {
        return new ResponseResource(true, "detail product repair", $repairProduct);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RepairProduct $repairProduct)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RepairProduct $repairProduct)
    {
        $validator = Validator::make($request->all(), [
            'new_barcode_product' => 'required',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            'new_category_product' => 'nullable',
            'new_tag_product' => 'nullable',
            'new_price_product' => 'nullable',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $repairProduct->update([
                'new_barcode_product' => $request->new_barcode_product,
                'new_name_product' => $request->new_name_product,
                'new_quantity_product' => $request->new_quantity_product,
                'old_price_product' => $request->old_price_product,
                'new_category_product' => $request->new_category_product,
                'new_tag_product' => $request->new_tag_product,
                'new_price_product' => $request->new_price_product,
            ]);

            $resource = new ResponseResource(true, "Data berhasil di ganti!", $repairProduct);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RepairProduct $repairProduct)
    {
        $userId = auth()->id();
        DB::beginTransaction();
        try {
            // Data untuk new_quality
            $qualityData = [
                'lolos' => 'lolos',
                'damaged' => null,
                'abnormal' => null,
            ];

            // Insert ke New_product
            $np =  New_product::create([
                'code_document' => $repairProduct->code_document,
                'old_barcode_product' => $repairProduct->old_barcode_product,
                'new_barcode_product' => $repairProduct->new_barcode_product,
                'new_name_product' => $repairProduct->new_name_product,
                'new_quantity_product' => $repairProduct->new_quantity_product,
                'new_price_product' => $repairProduct->old_price_product,
                'old_price_product' => $repairProduct->old_price_product,
                'new_date_in_product' => $repairProduct->new_date_in_product,
                'new_status_product' => 'display',
                'new_quality' => json_encode($qualityData),
                'new_category_product' => $repairProduct->new_category_product,
                'new_tag_product' => $repairProduct->new_tag_product,
                'new_discount' => $repairProduct->new_discount,
                'display_price' => $repairProduct->display_price,
                'type' => 'type1',
                'user_id' => $userId
            ]);

            // Update data repair
            $repair = Repair::findOrFail($repairProduct->repair_id);
            $updatedPrice = $repairProduct->old_price_product;

            $repair->update([
                'total_products' => $repair->total_products - 1,
                'total_price' => $repair->total_price - $updatedPrice,
                'total_custom_price' => $repair->total_custom_price - $updatedPrice,
            ]);

            // Hapus repairProduct setelah update
            $repairProduct->delete();

            // Jika tidak ada produk tersisa, hapus repair juga
            if ($repair->fresh()->total_products == 0) {
                $repair->delete();
            }

            // Commit transaksi
            DB::commit();

            // Return respons berhasil
            return new ResponseResource(true, "Produk repair berhasil dihapus", $np);
        } catch (\Exception $e) {
            // Rollback transaksi jika ada kesalahan
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus produk repair', 'error' => $e->getMessage()], 500);
        }
    }


    public function updateRepair($id)
    {
        // Mulai transaksi database
        DB::beginTransaction();

        $userId = auth()->id();

        try {

            $product = RepairProduct::find($id);
            $repair = Repair::where('id', $product->repair_id)->first();


            if ($product->new_status_product == 'dump') {
                // Batalkan transaksi dan kembalikan respons
                DB::rollback();
                return new ResponseResource(false, "status product sudah dump", $product);
            }
            if (!$product) {
                // Batalkan transaksi dan kembalikan respons
                DB::rollback();
                return new ResponseResource(false, "Produk tidak ditemukan", null);
            }

            $totalQuantity = $repair->total_products - 1;
            $totalPrice = $repair->total_price - $product->new_price_product;
            $totalCustomPrice = $repair->total_custom_price - $product->new_price_product;
            $user = User::find(auth()->id());
            // Perbarui entri Repair
            $repairProduct = Repair::where('id', $product->repair_id)->update([
                "user_id" => $user->id,
                "repair_name" => $repair->repair_name,
                "total_price" => $totalPrice,
                "total_custom_price" => $totalCustomPrice,
                "total_products" => $totalQuantity,
                "product_status" => $repair->product_status,
                "barcode" => $repair->barcode,
            ]);

            $getRepairProduct = Repair::find($product->repair_id);

            // Perbarui status produk menjadi 'dump'
            $product->update(['new_status_product' => 'dump']);

            New_product::create([
                "code_document" => $product->code_document,
                "old_barcode_product" => $product->old_barcode_product,
                "new_barcode_product" => $product->new_barcode_product,
                "new_name_product" => $product->new_name_product,
                "new_quantity_product" => $product->new_quantity_product,
                "new_price_product" => $product->new_price_product,
                "old_price_product" => $product->old_price_product,
                "new_date_in_product" => $product->new_date_in_product,
                "new_status_product" => $product->new_status_product,
                "new_quality" => $product->new_quality,
                "new_category_product" => $product->new_category_product,
                "new_tag_product" => $product->new_tag_product,
                'new_discount' => $product->new_discount,
                'display_price' => $product->display_price,
                'type' => $product->type,
                'user_id' => $userId
            ]);

            // Hancurkan objek produk
            $product->delete();
            // Cek apakah tidak ada RepairProduct lagi
            $remainingProducts = RepairProduct::where('repair_id', $product->repair_id)->count();
            if ($remainingProducts == 0) {
                // Hapus Repair jika tidak ada RepairProduct lagi
                $repair->delete();
            }

            // Commit transaksi karena operasi-operasi database berhasil
            DB::commit();

            return new ResponseResource(true, "data product sudah di update", $getRepairProduct);
        } catch (\Exception $e) {
            // Batalkan transaksi dan kembalikan respons jika terjadi kesalahan
            DB::rollback();
            return new ResponseResource(false, "Terjadi kesalahan saat memperbarui produk", null);
        }
    }
}
