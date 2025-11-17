<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Buyer;
use App\Models\Bundle;
use App\Models\BulkySale;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\BulkyDocument;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Exports\MultiSheetExport;
use App\Models\BagProducts;
use Maatwebsite\Excel\Facades\Excel;
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
        $bulkyDocument = $bulkyDocument->paginate(30);
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
        $bagProducts = BagProducts::where('bulky_document_id', $bulkyDocument->id)->get();

        foreach ($bagProducts as $bagProduct) {
            // Hitung total after_price_bulky_sale untuk setiap bag
            $bagProduct->price = BulkySale::where('bag_product_id', $bagProduct->id)
                ->sum('after_price_bulky_sale');
        }

        $bulkyDocument->total_bag = $bagProducts->count();
        $bulkyDocument->bag_products = $bagProducts;
        $resource = new ResponseResource(true, "data document bulky", $bulkyDocument);
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BulkyDocument $bulkyDocument)
    {
        $validator = Validator::make($request->all(), [
            'discount_bulky' => 'nullable|numeric|max:100',
            'buyer_id' => 'nullable|exists:buyers,id',
            'category_bulky' => 'nullable',
            'name_document' => 'required|unique:bulky_documents,name_document,' . $bulkyDocument->id,
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        $buyer = null;
        if ($request->filled('buyer_id')) {
            $buyer = Buyer::find($request->buyer_id);
        }

        $bulkyDocument->update([
            'discount_bulky' => $request['discount_bulky'] ?? 0,
            'buyer_id' => $buyer?->id,
            'name_buyer' => $buyer?->name_buyer,
            'category_bulky' => $request['category_bulky'] ?? '',
            'name_document' => $request['name_document']
        ]);

        return new ResponseResource(true, "berhasil mengupdate data", $bulkyDocument);
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
            //getData bulky sale
            $bulkySales = BulkySale::where('bulky_document_id', $bulkyDocument->id)->get();
            foreach($bulkySales as $bulkySale){
                //delete data di new product
                New_product::create([
                    'code_document' => $bulkySale->code_document ?? null,
                    'new_barcode_product' => $bulkySale->barcode_bulky_sale,
                    'old_barcode_product' => $bulkySale->old_barcode_product ?? null,
                    'new_name_product' => $bulkySale->name_product_bulky_sale,
                    'new_price_product' => $bulkySale->after_price_bulky_sale,
                    'old_price_product' => $bulkySale->old_price_bulky_sale,
                    'new_status_product' => 'display',
                    'new_quantity_product' => $bulkySale->qty ?? 1,
                    'new_date_in_product' => $bulkySale->new_date_in_product ?? Carbon::now('Asia/Jakarta')->format('Y-m-d'),
                    'new_quality' => json_encode(['lolos' => 'lolos']),
                    'created_at' => $bulkySale->new_date_in_product ?? Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    'updated_at' => Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    'display_price' => $bulkySale->after_price_bulky_sale,
                    'new_category_product' => $bulkySale->product_category_bulky_sale ?? null,
                    'new_tag_product' => null,
                    'user_id' => $bulkyDocument->user_id,
                    'is_so' => null,
                    'type' => 'type1',
                    'new_discount' => 0,
                    'user_so' => null,

                ]);
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
        DB::beginTransaction();
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');
        try {
            $id = $request->input('id');
            $user = auth()->user();

            $bulkyDocument = BulkyDocument::with(['bulkySales'])
                ->where('id', $id)
                ->where('status_bulky', 'proses')
                ->first();

            if (!$bulkyDocument) {
                $resource = new ResponseResource(false, "Data bulky belum dibuat!", null);
                return $resource->response()->setStatusCode(404);
            }

            $productBarcodes = $bulkyDocument->bulkySales->pluck('barcode_bulky_sale')->toArray();
            $totalProduct = count($productBarcodes);
            $diskon = $bulkyDocument->discount_bulky; // misal: 10 untuk 10%
            $oldPrice = $bulkyDocument->bulkySales->sum('old_price_bulky_sale');
            $totalAfterPrice = $oldPrice - ($oldPrice * $diskon / 100);

            // New_product::whereIn('new_barcode_product', $productBarcodes)->delete();
            // StagingProduct::whereIn('new_barcode_product', $productBarcodes)->delete();

            Bundle::whereIn('barcode_bundle', $productBarcodes)->update([
                'product_status' => 'sale',
            ]);

            BagProducts::where('bulky_document_id', $bulkyDocument->id)
                ->where('status', 'process')
                ->update(['status' => 'done']);
 

            // Memperbarui Bulky Document dengan total produk dan harga
            $bulkyDocument->update([
                'status_bulky' => 'selesai',
                'total_product_bulky' => $totalProduct,
                // 'total_old_price_bulky' => $totalOldPrice,
                'after_price_bulky' => $totalAfterPrice,
            ]);

            DB::commit();

            $resource = new ResponseResource(true, "Data bulky berhasil disimpan!", $totalProduct);
            return $resource->response();
        } catch (Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function createBulkyDocument(Request $request)
    {
        try {
            $user = auth()->user();
            $validator = Validator::make(
                $request->all(),
                [
                    'discount_bulky' => 'nullable|numeric',
                    // 'category_bulky' => 'nullable|string|max:255',
                    'buyer_id' => 'nullable|exists:buyers,id',
                    'name_document' => 'required|string|max:255',
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

            $baseName = $request->name_document;
            $lastDoc = BulkyDocument::orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($lastDoc && preg_match('/^(\d+)[\.\-]/', $lastDoc->name_document, $matches)) {
                $nextNumber = intval($matches[1]) + 1;
            } else {
                $nextNumber = 1;
            }

            $finalName = $nextNumber . '-' . $baseName;

            if (BulkyDocument::where('name_document', $finalName)->exists()) {
                DB::rollBack();
                return (new ResponseResource(false, "Nama dokumen sudah digunakan, silakan coba lagi.", null))->response()->setStatusCode(409);
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
                'category_bulky' => null,
                'status_bulky' => 'proses',
                'name_document' => $finalName,
            ]);

            $resource = new ResponseResource(true, "data start b2b berhasil di buat!", $bulkyDocument);
            return $resource->response();
        } catch (Exception $e) {
            $resource = new ResponseResource(false, "Gagal membuat dokumen bulky!", $e->getMessage());
            return $resource->response()->setStatusCode(422);
        }
    }

    public function export(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        // Ambil ID dari payload JSON
        $id = $request->input('id');
        $bulkyDocument = BulkyDocument::where('id', $id)->first();
        try {
            $fileName = $bulkyDocument->name_document . '-' . Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            // Buat direktori jika belum ada
            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            // Simpan file dengan MultiSheetExport
            Excel::store(new MultiSheetExport($request), $publicPath . '/' . $fileName, 'public');

            // URL download menggunakan public_path
            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }
}
