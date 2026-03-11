<?php

namespace App\Http\Controllers;

use App\Exports\BagProductExport;
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
use App\Models\Category;
use Illuminate\Support\Facades\Log;
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
        $typeFilter = $request->input('type');

        $bulkyDocumentQuery = BulkyDocument::latest();

        if ($query) {
            $bulkyDocumentQuery->where(function ($data) use ($query) {
                $data->where('code_document_bulky', 'LIKE', '%' . $query . '%')
                    ->orWhere('name_document', 'LIKE', '%' . $query . '%');
            });
        }

        if ($typeFilter) {
            $bulkyDocumentQuery->where('type', $typeFilter);
        }

        $bulkyDocumentPaginator = $bulkyDocumentQuery->paginate(30);

        $bulkyDocumentPaginator->getCollection()->transform(function ($document) {
            $document->status_so_text = ($document->is_so === 'done') ? 'Sudah SO' : 'Belum SO';

            $document->status_sale = match ($document->is_sale) {
                BulkyDocument::SALE_READY    => 'Siap Dijual',
                BulkyDocument::SALE          => 'Sudah Terjual',
                default                      => 'Belum Terjual',
            };

            $document->type_cargo = match ($document->type) {
                BulkyDocument::TYPE_OFFLINE  => 'Cargo Offline',
                BulkyDocument::TYPE_ONLINE   => 'Cargo Online',
                default                      => '',
            };

            return $document;
        });

        $resource = new ResponseResource(true, "list document bulky", $bulkyDocumentPaginator);
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
            foreach ($bulkySales as $bulkySale) {
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
                'is_sale' => BulkyDocument::SALE_NOT,
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
                    'discount_bulky' => 'nullable|numeric|min:0|max:100',
                    'buyer_id'       => 'nullable|exists:buyers,id',
                    'name_document'  => 'required|string|max:255',
                    'type'           => 'required|in:' . BulkyDocument::TYPE_OFFLINE . ',' . BulkyDocument::TYPE_ONLINE,
                ]
            );

            if ($validator->fails()) {
                $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
                return $resource->response()->setStatusCode(422);
            }

            $buyer = null;
            if ($request->filled('buyer_id')) {
                $buyer = \App\Models\Buyer::find($request->buyer_id);
            }

            DB::beginTransaction();

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
                'user_id'               => $user->id,
                'name_user'             => $user->name,
                'total_product_bulky'   => 0,
                'total_old_price_bulky' => 0,
                'buyer_id'              => $buyer?->id,
                'name_buyer'            => $buyer?->name_buyer,
                'discount_bulky'        => $request->discount_bulky ?? 0,
                'after_price_bulky'     => 0,
                'category_bulky'        => null,
                'status_bulky'          => 'proses',
                'name_document'         => $finalName,
                'is_sale'               => BulkyDocument::SALE_NOT,
                'type'                  => $request->type,
            ]);

            DB::commit();

            $resource = new ResponseResource(true, "Data dokumen Cargo berhasil dibuat!", $bulkyDocument);
            return $resource->response();
        } catch (\Exception $e) {
            DB::rollBack();

            $resource = new ResponseResource(false, "Gagal membuat dokumen cargo!", $e->getMessage());
            return $resource->response()->setStatusCode(500);
        }
    }

    public function export(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $id = $request->input('id');
        $bulkyDocument = BulkyDocument::where('id', $id)->first();

        if (!$bulkyDocument) {
            return new ResponseResource(false, "Dokumen tidak ditemukan", null);
        }

        try {
            $fileName = $bulkyDocument->name_document . '-' . \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d') . '.xlsx';
            $publicPath = 'temp-exports';
            $filePath = $publicPath . '/' . $fileName;

            if (!\Illuminate\Support\Facades\Storage::disk('public_direct')->exists($publicPath)) {
                \Illuminate\Support\Facades\Storage::disk('public_direct')->makeDirectory($publicPath);
            }

            if (\Illuminate\Support\Facades\Storage::disk('public_direct')->exists($filePath)) {
                \Illuminate\Support\Facades\Storage::disk('public_direct')->delete($filePath);
            }

            $bags = BagProducts::with('bulkySales')
                ->where('bulky_document_id', $id)
                ->get();

            Excel::store(
                new BagProductExport($bags),
                $filePath,
                'public_direct'
            );

            $downloadUrl = url($filePath) . '?t=' . time();

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function confirmSale(Request $request, $id)
    {
        $doc = BulkyDocument::with('bulkySales')->findOrFail($id);
        $isOnline = $doc->type === BulkyDocument::TYPE_ONLINE;

        $validator = Validator::make($request->all(), [
            'buyer_id'       => $isOnline ? 'nullable|exists:buyers,id' : 'required|exists:buyers,id',
            'discount_bulky' => $isOnline ? 'nullable|numeric|min:0|max:100' : 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        if ($doc->is_sale === BulkyDocument::SALE) {
            return (new ResponseResource(false, "Dokumen ini sudah berstatus terjual!", null))->response()->setStatusCode(400);
        }

        if ($doc->status_bulky === 'proses') {
            return (new ResponseResource(false, "Dokumen ini masih proses!", null))->response()->setStatusCode(400);
        }

        if ($doc->type === BulkyDocument::TYPE_ONLINE && $doc->is_sale !== BulkyDocument::SALE_READY) {
            return (new ResponseResource(false, "Dokumen Cargo Online belum siap dijual (Ready)! Silakan lengkapi data dimensi/armada terlebih dahulu.", null))->response()->setStatusCode(400);
        }

        $buyer = $request->filled('buyer_id') ? \App\Models\Buyer::find($request->buyer_id) : null;

        $discountBulky = $request->discount_bulky ?? 0;

        DB::beginTransaction();
        try {
            $totalAfterPrice = 0;
            foreach ($doc->bulkySales as $item) {
                $newPrice = $item->old_price_bulky_sale - ($item->old_price_bulky_sale * $discountBulky / 100);
                $item->update(['after_price_bulky_sale' => $newPrice]);
                $totalAfterPrice += $newPrice;
            }

            $doc->update([
                'is_sale'           => BulkyDocument::SALE,
                'buyer_id'          => $buyer ? $buyer->id : null,
                'name_buyer'        => $buyer ? $buyer->name_buyer : null,
                'discount_bulky'    => $discountBulky,
                'after_price_bulky' => $totalAfterPrice,
            ]);

            DB::commit();
            return (new ResponseResource(true, "Cargo {$doc->type} berhasil terjual!", $doc))->response();
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Error: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function setOnlineReady(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'length' => 'required|numeric',
            'width' => 'required|numeric',
            'height' => 'required|numeric',
            'weight' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input dimensi tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        $doc = BulkyDocument::with('bulkySales')->findOrFail($id);

        if ($doc->is_sale === BulkyDocument::SALE) {
            return (new ResponseResource(false, "Dokumen ini sudah berstatus terjual!", null))->response()->setStatusCode(400);
        }

        if ($doc->status_bulky === 'proses') {
            return (new ResponseResource(false, "Dokumen ini masih proses!", null))->response()->setStatusCode(400);
        }

        DB::beginTransaction();
        try {
            $doc->update([
                'is_sale'          => BulkyDocument::SALE_READY,
                'length'           => $request->length,
                'width'            => $request->width,
                'height'           => $request->height,
                'weight'           => $request->weight,
                'fleet_estimation' => $request->fleet_estimation ?? null,
            ]);

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.cargo_online', ['doc' => $doc]);

            $fileName = 'Cargo-Online-' . $doc->id . '.pdf';
            $filePath = 'public/pdfs/cargo/' . $fileName;

            \Illuminate\Support\Facades\Storage::put($filePath, $pdf->output());

            DB::commit();
            return (new ResponseResource(true, "Dokumen berhasil diubah menjadi ready dan PDF berhasil di-generate!", $doc))->response();
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan sistem: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function getSummaryBulkySales()
    {
        try {
            $salesData = BulkyDocument::selectRaw('type, SUM(total_product_bulky) as qty, SUM(after_price_bulky) as total_price, SUM(total_old_price_bulky) as total_old_price')
                ->where('is_sale', BulkyDocument::SALE)
                ->whereNotNull('type')
                ->groupBy('type')
                ->get()
                ->keyBy('type');

            $offlineData = $salesData->get(BulkyDocument::TYPE_OFFLINE);
            $onlineData = $salesData->get(BulkyDocument::TYPE_ONLINE);

            $summary = [
                'cargo_offline' => [
                    'qty'             => $offlineData ? (int) $offlineData->qty : 0,
                    'total_price'     => $offlineData ? (float) $offlineData->total_price : 0,
                    'total_old_price' => $offlineData ? (float) $offlineData->total_old_price : 0,
                ],
                'cargo_online' => [
                    'qty'             => $onlineData ? (int) $onlineData->qty : 0,
                    'total_price'     => $onlineData ? (float) $onlineData->total_price : 0,
                    'total_old_price' => $onlineData ? (float) $onlineData->total_old_price : 0,
                ],
                'akumulasi_total' => [
                    'qty'             => ($offlineData ? $offlineData->qty : 0) + ($onlineData ? $onlineData->qty : 0),
                    'total_price'     => ($offlineData ? $offlineData->total_price : 0) + ($onlineData ? $onlineData->total_price : 0),
                    'total_old_price' => ($offlineData ? $offlineData->total_old_price : 0) + ($onlineData ? $onlineData->total_old_price : 0),
                ]
            ];

            return (new ResponseResource(true, "Berhasil mengambil summary penjualan cargo", $summary))->response();
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal mengambil data summary: " . $e->getMessage(), null))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function getWaitingCargoOnline()
    {
        try {
            $documents = BulkyDocument::where('type', BulkyDocument::TYPE_ONLINE)
                ->where('is_sale', BulkyDocument::SALE_READY)
                ->get();

            $data = $documents->map(function ($doc) {
                return [
                    'id'            => $doc->id,
                    'name_document' => $doc->name_document,
                    'old_price'    => (float) $doc->total_old_price_bulky,
                    'dimension'       => [
                        'length' => (float) $doc->length,
                        'width'  => (float) $doc->width,
                        'height' => (float) $doc->height,
                        'weight' => (float) $doc->weight,
                    ],
                    'volume'        => (float) ($doc->length * $doc->width * $doc->height),
                    'pdf_url'       => url("/api/cargo-online/{$doc->id}/pdf"),
                ];
            });

            return response()->json([
                'status'  => true,
                'message' => 'list cargo online waiting upload',
                'data'    => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
                'data'    => null
            ], 500);
        }
    }

    public function exportPdfBuffer($id)
    {
        $doc = BulkyDocument::where('type', BulkyDocument::TYPE_ONLINE)
            ->where('is_sale', BulkyDocument::SALE_READY)
            ->find($id);

        if (!$doc) {
            return response()->json([
                'status'  => false,
                'message' => 'Dokumen tidak ditemukan atau belum siap!'
            ], 404);
        }

        $fileName = 'Cargo-Online-' . $doc->id . '.pdf';

        $filePath = storage_path('app/public/pdfs/cargo/' . $fileName);

        if (!file_exists($filePath)) {
            return response()->json([
                'status' => false,
                'message' => 'File PDF fisik belum ter-generate di server! Pastikan Anda sudah mengisi dimensi (set-online-ready) untuk dokumen ini.'
            ], 404);
        }

        return response()->file($filePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"'
        ]);
    }
}
