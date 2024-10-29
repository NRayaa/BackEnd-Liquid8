<?php

namespace App\Http\Controllers;

use App\Models\ProductInput;
use Illuminate\Http\Request;
use App\Models\FilterProductInput;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;

class FilterProductInputController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $searchQuery = $request->input('q');
        $newProducts = FilterProductInput::latest()
            ->where(function ($queryBuilder) use ($searchQuery) {
                $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            })
            ->whereNotIn('new_status_product', ['dump', 'expired', 'sale', 'migrate', 'repair'])
            ->whereNull('new_tag_product')
            ->paginate(30);

        return new ResponseResource(true, "list new product", $newProducts);
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
            $product = ProductInput::findOrFail($id);
            $product->user_id = $userId;

            $productFilter = FilterProductInput::create($product->toArray());
            $product->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menambah list product bkl", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(FilterProductInput $filterProductInput)
    {
        return new ResponseResource(true, 'product', $filterProductInput);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FilterProductInput $filterProductInput)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FilterProductInput $filterProductInput)
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
            $product_filter = FilterProductInput::findOrFail($id);
            ProductInput::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product filter", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
