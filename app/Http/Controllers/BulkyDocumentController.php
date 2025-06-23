<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\BulkyDocument;
use App\Models\StagingProduct;
use App\Http\Resources\ResponseResource;
use Exception;
use Illuminate\Support\Facades\Validator;

class BulkyDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $bulkyDocument = BulkyDocument::latest();
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

    public function bulkySaleFinish(Request $request)
    {
        $id = $request->input('id');
        $user = auth()->user();
        $bulkyDocument = BulkyDocument::with('bulkySales')
            ->where('id', $id)
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

    public function createBulkyDocument(Request $request)
    {
        try {
            $user = auth()->user();
            $validator = Validator::make(
                $request->all(),
                [
                    'discount_bulky' => 'nullable|numeric',
                    'category_bulky' => 'nullable|string|max:255',
                    'buyer_id' => 'nullable|exists:buyers,id',
                    'name_document' => 'required|string|max:255|unique:bulky_documents,name_document',
                ]
            );

            if ($validator->fails()) {
                $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
                return $resource->response()->setStatusCode(422);
            }

            $buyer = null;
            if ($request->filled('buyer_id')) {
                $buyer = Buyer::find($request->buyer_id);
            }

            $bulkyDocument = BulkyDocument::create([
                'user_id' => $user->id,
                'name_user' => $user->name,
                'total_product_bulky' => 0,
                'total_old_price_bulky' => 0,
                'buyer_id' => $buyer?->id,
                'name_buyer' => $buyer?->name_buyer,
                'discount_bulky' => $request->discount_bulky ?? 0,
                'after_price_bulky' => 0,
                'category_bulky' => $request->category_bulky ?? '',
                'status_bulky' => 'proses',
                'name_document' => $request->name_document,
            ]);

            $resource = new ResponseResource(true, "data start b2b berhasil di buat!", $bulkyDocument);
            return $resource->response();
        } catch (Exception $e) {
            $resource = new ResponseResource(false, "Gagal membuat dokumen bulky!", $e->getMessage());
            return $resource->response()->setStatusCode(422);
        }
    }
}
