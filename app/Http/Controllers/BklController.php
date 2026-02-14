<?php

namespace App\Http\Controllers;

use App\Exports\ProductBkl;
use App\Http\Resources\ResponseResource;
use App\Models\BklDocument;
use App\Models\BklItem;
use App\Models\BklProduct; // Tambahan Baru
use App\Models\Destination; // Tambahan Baru
use App\Services\Olsera\OlseraService; // Tambahan Baru
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Tambahan Baru
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

class BklController extends Controller
{
    public function listBklDocument(Request $request)
    {
        $query = BklDocument::query();

        if ($request->has('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('code_document_bkl', 'like', '%' . $search . '%');
            });
        }
        $documents = $query->latest()->paginate(10);
        return new ResponseResource(true, "List BKL Documents", $documents);
    }

    public function storeBklDocument(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();
            $validator = Validator::make($request->all(), [
                'name_document' => 'required|string|unique:bkl_documents,code_document_bkl',
                'type' => 'required|in:in,out',
                'damage_qty' => 'nullable|integer|min:1',
                'colors' => 'nullable|array',
                'colors.*.color_tag_id' => 'required|exists:color_tags,id',
                'colors.*.qty' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => $validator->errors()], 422);
            }

            if (!$request->damage_qty && empty($request->colors)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Harus mengisi minimal Quantity Damage atau satu Warna.'
                ], 422);
            }

            $document = BklDocument::create([
                'code_document_bkl' => $request->name_document,
                'status' => 'done',
                'user_id' => $user->id
            ]);

            $this->saveItems($document->id, $request);

            DB::commit();
            return new ResponseResource(true, "BKL Berhasil Dibuat (Done)", $document->load('items'));
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
        }
    }

    public function detailBklDocument($id)
    {
        $document = BklDocument::with('items.colorTag')->find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
        }

        return new ResponseResource(true, "Detail BKL Document", $document);
    }

    public function toEdit($id)
    {
        $document = BklDocument::find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
        }

        if ($document->status === 'process') {
            return response()->json(['message' => 'Dokumen ini sudah dalam mode edit (Process)'], 400);
        }

        $document->update(['status' => 'process']);

        return new ResponseResource(true, "Mode Edit Aktif (Status: Process)", $document);
    }

    public function updateBklDocument(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $document = BklDocument::find($id);

            if (!$document) {
                return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
            }

            if ($document->status === 'done') {
                return response()->json(['message' => 'Dokumen terkunci (Done). Klik tombol Edit terlebih dahulu.'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name_document' => 'required|string|unique:bkl_documents,code_document_bkl,' . $id,
                'type' => 'required|in:in,out',
                'damage_qty' => 'nullable|integer|min:1',
                'colors' => 'nullable|array',
                'colors.*.color_tag_id' => 'required|exists:color_tags,id',
                'colors.*.qty' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $document->update([
                'code_document_bkl' => $request->name_document,
                'status' => 'done',
            ]);

            BklItem::where('bkl_document_id', $document->id)->delete();
            $this->saveItems($document->id, $request);

            DB::commit();
            return new ResponseResource(true, "BKL Berhasil Diupdate (Done)", $document->load('items'));
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal update', 'error' => $e->getMessage()], 500);
        }
    }

    private function saveItems($documentId, $request)
    {
        $type = $request->type;

        if ($request->has('damage_qty') && $request->damage_qty > 0) {
            BklItem::create([
                'bkl_document_id' => $documentId,
                'type' => $type,
                'qty' => $request->damage_qty,
                'color_tag_id' => null,
                'is_damaged' => true
            ]);
        }

        if ($request->has('colors') && is_array($request->colors)) {
            foreach ($request->colors as $colorItem) {
                BklItem::create([
                    'bkl_document_id' => $documentId,
                    'type' => $type,
                    'qty' => $colorItem['qty'],
                    'color_tag_id' => $colorItem['color_tag_id'],
                    'is_damaged' => null
                ]);
            }
        }
    }

    public function generateCode()
    {
        try {
            $userId = auth()->id();
            $lastDoc = BklDocument::latest('id')->first();

            if (!$lastDoc) {
                $nextSequence = 1;
            } else {
                $parts = explode('-', $lastDoc->code_document_bkl);
                $lastNumber = (int) end($parts);
                $nextSequence = $lastNumber + 1;
            }

            $generatedCode = sprintf("%d-BKL-%06d", $userId, $nextSequence);

            return new ResponseResource(true, 'Berhasil generate code', [
                'code_document_bkl' => $generatedCode
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function listOlseraOutgoing(Request $request)
    {
        try {
            $request->validate(['destination_id' => 'required|exists:destinations,id']);
            
            $destination = Destination::find($request->destination_id);
            $olseraService = new OlseraService($destination);

            $response = $olseraService->getOutgoingStockList();

            if (!$response['success']) {
                return (new ResponseResource(false, 'Gagal menarik data dari Olsera: ' . $response['message'], null))
                    ->response()->setStatusCode(500);
            }

            $items = $response['data']['data'] ?? [];

            $draftDocuments = collect($items)->filter(function ($item) {
                return isset($item['status']) && $item['status'] === 'D';
            })->values();

            return new ResponseResource(true, "List Dokumen Outgoing Olsera (Draft)", $draftDocuments);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function detailOlseraOutgoing(Request $request, $id)
    {
        try {
            $request->validate(['destination_id' => 'required|exists:destinations,id']);
            
            $destination = Destination::find($request->destination_id);
            $olseraService = new OlseraService($destination);

            $response = $olseraService->getStockInOutDetail(['id' => $id]);

            if (!$response['success']) {
                return (new ResponseResource(false, 'Gagal menarik detail dari Olsera: ' . $response['message'], null))
                    ->response()->setStatusCode(500);
            }

            return new ResponseResource(true, "Detail Dokumen Outgoing Olsera", $response['data']);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function processOlseraOutgoing(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();
            
            $validator = Validator::make($request->all(), [
                'destination_id' => 'required|exists:destinations,id',
                'olsera_document_id' => 'required',
                'olsera_document_code' => 'required|string', 
                'items' => 'required|array',
                'items.*.price_category' => 'required|string',
                'items.*.color_qty' => 'nullable|integer|min:0',
                'items.*.color_tag' => 'nullable|string',
                'items.*.damage_qty' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => $validator->errors()], 422);
            }

            $destination = Destination::find($request->destination_id);
            $olseraService = new OlseraService($destination);

            $updateResponse = $olseraService->updateStatusStockInOut([
                'pk' => $request->olsera_document_id,
                'status' => 'P'
            ]);

            if (!$updateResponse['success']) {
                throw new \Exception("Gagal Publish Dokumen di Olsera: " . $updateResponse['message']);
            }

            $document = BklDocument::firstOrCreate(
                ['code_document_bkl' => $request->olsera_document_code],
                [
                    'status' => 'done',
                    'user_id' => $user->id
                ]
            );

            $tanggalMasuk = now()->format('Y-m-d');
            
            foreach ($request->items as $item) {
                $newProductName = 'BKL ' . $item['price_category']; 

                // Generate Produk Lolos QC
                $colorQty = $item['color_qty'] ?? 0;
                for ($i = 0; $i < $colorQty; $i++) {
                    $barcode = 'BKL-' . strtoupper(Str::random(10));
                    
                    BklProduct::create([
                        'code_document' => $request->olsera_document_code,
                        'old_barcode_product' => $barcode,
                        'new_barcode_product' => $barcode,
                        'new_name_product' => $newProductName,
                        'new_quantity_product' => 1,
                        'new_status_product' => 'display',
                        'new_quality' => ['lolos' => 'lolos'],
                        'new_tag_product' => $item['color_tag'] ?? null,
                        'new_date_in_product' => $tanggalMasuk,
                        'display_price' => 0, 
                    ]);
                }

                $damageQty = $item['damage_qty'] ?? 0;
                for ($i = 0; $i < $damageQty; $i++) {
                    $barcode = 'BKL-' . strtoupper(Str::random(10));
                    
                    BklProduct::create([
                        'code_document' => $request->olsera_document_code,
                        'old_barcode_product' => $barcode,
                        'new_barcode_product' => $barcode,
                        'new_name_product' => $newProductName,
                        'new_quantity_product' => 1,
                        'new_status_product' => 'display',
                        'new_quality' => ['damaged' => 'damaged'],
                        'new_tag_product' => null,
                        'new_date_in_product' => $tanggalMasuk,
                        'display_price' => 0,
                    ]);
                }
            }

            if (function_exists('logUserAction')) {
                logUserAction($request, $user, 'QC BKL', "Proses QC Olsera Outgoing Dokumen: {$request->olsera_document_code}");
            }

            DB::commit();
            return new ResponseResource(true, "QC Selesai! Dokumen Olsera di-publish & Produk BKL berhasil dibuat.", $document);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Proses QC BKL Error: " . $e->getMessage());
            return (new ResponseResource(false, 'Gagal memproses BKL: ' . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }
}