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

        $product_filters = RepairFilter::where('user_id', $userId)->latest()->paginate(100);

        $allFilters = RepairFilter::where('user_id', $userId)->get();

        $totalNewPrice = $allFilters->sum(function ($item) {
            return $item->new_price_product > 0 ? $item->new_price_product : $item->old_price_product;
        });

        return new ResponseResource(true, "List product di keranjang repair filter", [
            'total_estimated_price' => $totalNewPrice,
            'total_items' => $allFilters->count(),
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
            $product = New_product::where('id', $id)->first();

            if (!$product) {
                return new ResponseResource(false, "Produk tidak ditemukan di inventory", null);
            }

            $existsInFilter = RepairFilter::where('new_barcode_product', $product->new_barcode_product)
                ->where('user_id', $userId)
                ->exists();

            if ($existsInFilter) {
                return new ResponseResource(false, "Produk ini sudah ada di list filter Anda", null);
            }

            $productData = $product->toArray();
            $productData['user_id'] = $userId;
            $productData['created_at'] = now();
            $productData['updated_at'] = now();

            $repairFilter = RepairFilter::create($productData);

            $product->delete();

            DB::commit();
            return new ResponseResource(true, "Berhasil masuk ke filter repair", $repairFilter);
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
            $repairFilter = RepairFilter::findOrFail($id);

            $productData = $repairFilter->toArray();

            unset($productData['id']);
            $productData['new_status_product'] = 'display';

            New_product::create($productData);

            $repairFilter->delete();

            DB::commit();
            return new ResponseResource(true, "Item dikembalikan ke inventory (Batal Repair)", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
