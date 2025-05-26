<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BulkyDocument;
use App\Models\Bundle;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;

class BulkyDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $bulkyDocument = BulkyDocument::where('status_bulky', 'selesai')->latest();
        if ($query) {
            $bulkyDocument = $bulkyDocument->where(function ($data) use ($query) {
                $data->where('code_document_bulky', 'LIKE', '%' . $query . '%');
            });
        }
        $bulkyDocument = $bulkyDocument->paginate(10);
        $resource = new ResponseResource(true, "list document bulky", $bulkyDocument);
        return $resource->response();
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
    public function show(BulkyDocument $bulkyDocument)
    {
        $resource = new ResponseResource(true, "data document bulky", $bulkyDocument->load('bulkySales'));
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BulkyDocument $bulkyDocument)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BulkyDocument $bulkyDocument)
    {
        try {
            if ($bulkyDocument->status_bulky === 'proses') {
                $resource = new ResponseResource(false, "data bulky tidak bisa di hapus karena masih proses!", null);
                return $resource->response()->setStatusCode(400);
            }
            $bulkyDocument->delete();
            $resource = new ResponseResource(true, "data berhasil di hapus!", $bulkyDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "data gagal di hapus!", $e->getMessage());
        }
        return $resource->response();
    }

    public function bulkySaleFinish()
    {
        $user = auth()->user();
        $bulkyDocument = BulkyDocument::with('bulkySales')
            ->where('user_id', $user->id)
            ->where('status_bulky', 'proses')
            ->first();

        if (!$bulkyDocument) {
            $resource = new ResponseResource(false, "data bulky belum di buat!", null);
            return $resource->response()->setStatusCode(404);
        }

        $bulkySales = $bulkyDocument->bulkySales;
        $productBarcodes = $bulkySales->pluck('barcode_bulky_sale')->toArray();

        New_product::whereIn('new_barcode_product', $productBarcodes)->delete();
        StagingProduct::whereIn('new_barcode_product', $productBarcodes)->delete();
        Bundle::whereIn('barcode_bundle', $productBarcodes)->update([
            'product_status' => 'sale',
        ]);

        $totalProduct = $bulkySales->count();
        $totalOldPrice = $bulkySales->sum('old_price_bulky_sale');
        $totalAfterPrice = $bulkySales->sum('after_price_bulky_sale');

        $bulkyDocument->update([
            'status_bulky' => 'selesai',
            'total_product_bulky' => $totalProduct,
            'total_old_price_bulky' => $totalOldPrice,
            'after_price_bulky' => $totalAfterPrice,
        ]);

        $resource = new ResponseResource(true, "data bulky berhasil di simpan!", $bulkyDocument->load('bulkySales'));
        return $resource->response();
    }
}
