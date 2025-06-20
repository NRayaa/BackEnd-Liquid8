<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Illuminate\Http\Request;

class BagProductsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $q        = $request->input('q');
        $docId    = $request->input('id');
        $userId   = auth()->id();
        $perPage  = $request->input('per_page', 10);

        $bagProduct = BagProducts::where('bulky_document_id', $docId)
            ->where('user_id', $userId)
            ->first();

        $bulkyDocument = BulkyDocument::find($docId);

        if (!$bagProduct) {
            $bulkyDocument->bulky_sales = $bulkyDocument->bulkySales; // eager loaded
            return new ResponseResource(true, 'List of bag products', [
                'bulky_document' => $bulkyDocument,
                'bag_product' => null,
            ]);
        }

        $bulkySales = BulkySale::where('bag_product_id', $bagProduct->id)
            ->when($q, function ($query) use ($q) {
                $query->where('barcode_bulky_sale', 'like', "%{$q}%")
                    ->orWhere('name_product_bulky_sale', 'like', "%{$q}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $bagProduct->bulky_sales       = $bulkySales->items();
        $bagProduct->total_bulky_sales = $bulkySales->total();
        $bagProduct->bulky_sales_meta  = [
            'current_page' => $bulkySales->currentPage(),
            'last_page'    => $bulkySales->lastPage(),
            'per_page'     => $bulkySales->perPage(),
            'total'        => $bulkySales->total(),
        ];

        return new ResponseResource(true, 'Detail bag product with paginated items', [
            'bulky_document' => $bulkyDocument,
            'bag_product'    => $bagProduct
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
