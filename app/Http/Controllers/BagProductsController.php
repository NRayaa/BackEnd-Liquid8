<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use Illuminate\Http\Request;

class BagProductsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $id = $request->input('id');

        $userId = auth()->id();

        // Mulai dengan query utama
        $bagProductsQuery = BagProducts::with(['bulkySales'])->where('bulky_document_id', $id)->where('user_id', $userId);

   
        if ($query) {
            $bagProductsQuery->whereHas('bulkySales', function ($q) use ($query) {
                $q->where('barcode_bulky_sale', 'LIKE', '%' . $query . '%')
                    ->orWhere('name_product_bulky_sale', 'LIKE', '%' . $query . '%');
            });
        }

        // Mendapatkan hasil dengan paginasi
        $bagProducts = $bagProductsQuery->latest()->paginate(15);

        return new ResponseResource(true, "List of bag products", $bagProducts);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(BagProducts $bagProducts)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BagProducts $bagProducts)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BagProducts $bagProducts)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BagProducts $bagProducts)
    {
        //
    }
}
