<?php

namespace App\Http\Controllers;

use App\Models\RepairFilter;
use Illuminate\Http\Request;
use App\Models\New_product;
use App\Models\RepairProduct;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\Repair;

class RepairFilterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId = auth()->id();
        $product_filtersByuser = RepairFilter::where('user_id', $userId)->get();
        $totalNewPrice = $product_filtersByuser->sum('new_price_product');

        $totalNewPriceWithCategory = $product_filtersByuser->whereNotNull('new_category_product')->sum('new_price_product');
        $totalOldPriceWithoutCategory = $product_filtersByuser->whereNull('new_category_product')->sum('old_price_product');
        $totalNewPriceWithoutCtgrTagColor = $product_filtersByuser
            ->whereNull('new_category_product')->whereNull('new_tag_product')->whereNull('old_price_product')->sum('new_price_product');
        $totalOldPriceWithoutCtgrTagColor = $product_filtersByuser->whereNull('new_category_product')
            ->whereNull('new_tag_product')->whereNull('new_price_product')->sum('old_price_product');


        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory + $totalNewPriceWithoutCtgrTagColor + $totalOldPriceWithoutCtgrTagColor;
        $product_filters = RepairFilter::where('user_id', $userId)->paginate(100);
        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'data' => $product_filters,
        ]);
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
    public function store($id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product = New_product::where('id', $id)->whereNot('new_status_product', 'repair')->first();
            if(!$product){
                return new ResponseResource(false, "Produk tidak ditemukan atau sudah dalam status repair", null);
            }
            $checkExisting = RepairProduct::where('new_barcode_product', $product->new_barcode_product)
                ->where(function($query) {
                    $query->where('new_status_product', 'stage_repair')
                          ->orWhere('new_status_product', 'repair');
                })
                ->first();
            if ($checkExisting) {
                return new ResponseResource(false, "Produk dengan barcode yang sama sudah ada dalam daftar repair", null);
            }
            $product->user_id = $userId;
            $repair = Repair::where('user_id', $userId)->first();
            if (!$repair) {
                $repair = Repair::create([
                    'user_id' => $userId,
                    'barcode' => barcodeRepair(),
                    'repair_name' => 'proses repair oleh user id ' . $userId,
                    'total_price' => 0,
                    'total_custom_price' => 0,
                    'total_product' => 0,
                    'product_status' => 'not_sale',
                    'created_at' => now('Asia/Jakarta'),
                    'updated_at' => now('Asia/Jakarta'),
                ]);
            }
            $productFilter = RepairProduct::create([
                'repair_id' => $repair->id,
                'code_document' => $product->code_document,
                'user_id' => $userId,
                'old_barcode_product' => $product->old_barcode_product,
                'new_barcode_product' => $product->new_barcode_product,
                'old_price_product' => $product->old_price_product,
                'new_price_product' => $product->new_price_product,
                'display_price' => $product->display_price,
                'new_category_product' => $product->new_category_product,
                'new_tag_product' => $product->new_tag_product,
                'new_name_product' => $product->new_name_product,
                'new_quantity_product' => $product->new_quantity_product,
                'new_status_product' => 'stage_repair',
                'new_quality' => $product->new_quality,
                'new_date_in_product' => $product->new_date_in_product,
                'new_discount' => $product->new_discount,
                'type' => $product->type,
                'actual_old_price_product' => $product->actual_old_price_product ?? $product->old_price_product,
                'actual_new_quality' => $product->actual_new_quality ?? $product->new_quality,
                'actual_created_at' => $product->actual_created_at ?? $product->created_at,
                
            ]);
            $product->update(['new_status_product' => 'repair']);
            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product reapir", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    public function store2($id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product = New_product::findOrFail($id);
            $product->user_id = $userId;
            $productFilter = RepairFilter::create($product->toArray());
            $product->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product reapir", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(RepairFilter $repairFilter)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RepairFilter $repairFilter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RepairFilter $repairFilter)
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
            $product_filter = RepairFilter::findOrFail($id);
            New_product::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product repair", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
