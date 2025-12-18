<?php

namespace App\Http\Controllers;

use App\Models\ScrapDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapDocumentController extends Controller
{
    public function index(Request $request)
    {

        $q = $request->query('q');
        $status = $request->query('status');
        $perPage = $request->query('per_page', 15);


        $query = ScrapDocument::with('user:id,name')
            ->latest();

        if ($q) {
            $query->where(function ($subQuery) use ($q) {
                $subQuery->where('code_document_scrap', 'LIKE', '%' . $q . '%')
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery->where('name', 'LIKE', '%' . $q . '%');
                    });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $documents = $query->paginate($perPage);

        return (new ResponseResource(true, "List Data Scrap Documents", $documents))
            ->response()->setStatusCode(200);
    }

    public function getActiveSession()
    {
        $user = auth()->user();

        $doc = ScrapDocument::where('user_id', $user->id)
            ->where('status', 'proses')
            ->first();

        if (!$doc) {
            do {
                $random = strtoupper(Str::random(6));
                $code = $user->id . '-SCR-' . $random;
            } while (ScrapDocument::where('code_document_scrap', $code)->exists());

            $doc = ScrapDocument::create([
                'code_document_scrap' => $code,
                'user_id' => $user->id,
                'status' => 'proses',
            ]);

            $data = [
                'document' => $doc,
                'items' => []
            ];
            return (new ResponseResource(true, "Sesi Scrap Baru Berhasil Dibuat", $data))
                ->response()->setStatusCode(201);
        } else {
            $this->recalculateTotals($doc->id);
            $displayItems = New_product::where('scrap_document_id', $doc->id)
                ->get()
                ->map(function ($item) {
                    $item['source'] = 'display';
                    return $item;
                });

            $stagingItems = StagingProduct::where('scrap_document_id', $doc->id)
                ->get()
                ->map(function ($item) {
                    $item['source'] = 'staging';
                    return $item;
                });

            $allItems = $displayItems->merge($stagingItems);
            $data = [
                'document' => $doc,
                'items' => $allItems
            ];

            return (new ResponseResource(true, "Sesi Scrap Aktif Ditemukan", $data))
                ->response()->setStatusCode(200);
        }
    }

    public function addProductToScrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scrap_document_id' => 'required|exists:scrap_documents,id',
            'product_id' => 'required',
            'source' => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($request->scrap_document_id);


            if ($doc->status == 'selesai') {
                return (new ResponseResource(false, "Dokumen ini sudah selesai diproses!", null))
                    ->response()->setStatusCode(422);
            }

            $product = $request->source == 'staging'
                ? StagingProduct::find($request->product_id)
                : New_product::find($request->product_id);

            if (!$product) {
                return (new ResponseResource(false, "Produk tidak ditemukan!", null))
                    ->response()->setStatusCode(404);
            }

            if ($product->new_status_product !== 'dump') {
                return (new ResponseResource(false, "Hanya produk status 'dump' yang bisa di-scrap!", null))
                    ->response()->setStatusCode(422);
            }

            if ($product->scrap_document_id !== null && $product->scrap_document_id != $doc->id) {
                return (new ResponseResource(false, "Produk sedang dalam proses scrap di dokumen lain!", null))
                    ->response()->setStatusCode(422);
            }

            $product->update(['scrap_document_id' => $doc->id]);

            $this->recalculateTotals($doc->id);

            DB::commit();
            return (new ResponseResource(true, "Produk berhasil ditambahkan ke list scrap", null))
                ->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Scrap Add Product Error: " . $e->getMessage());
            return (new ResponseResource(false, "Terjadi kesalahan server: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function addAllDumpToCart(Request $request)
    {
        $docId = $request->scrap_document_id;
        $doc = ScrapDocument::find($docId);

        if (!$doc) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                ->response()->setStatusCode(404);
        }

        if ($doc->status == 'selesai') {
            return (new ResponseResource(false, "Dokumen sudah selesai, tidak bisa diubah", null))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {


            $updatedDisplay = New_product::where('new_status_product', 'dump')
                ->whereNull('scrap_document_id')
                ->update(['scrap_document_id' => $docId]);

            $updatedStaging = StagingProduct::where('new_status_product', 'dump')
                ->whereNull('scrap_document_id')
                ->update(['scrap_document_id' => $docId]);

            $total = $updatedDisplay + $updatedStaging;

            if ($total == 0) {
                return (new ResponseResource(false, "Tidak ada produk dump baru yang tersedia.", null))
                    ->response()->setStatusCode(404);
            }

            $this->recalculateTotals($docId);

            DB::commit();
            return (new ResponseResource(true, "$total produk dump berhasil masuk scrap.", null))
                ->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Scrap Add All Error: " . $e->getMessage());
            return (new ResponseResource(false, "Terjadi kesalahan server: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function removeProductFromScrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scrap_document_id' => 'required',
            'product_id' => 'required',
            'source' => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $product = $request->source == 'staging'
                ? StagingProduct::find($request->product_id)
                : New_product::find($request->product_id);

            if ($product && $product->scrap_document_id == $request->scrap_document_id) {

                $product->update(['scrap_document_id' => null]);

                $this->recalculateTotals($request->scrap_document_id);

                DB::commit();
                return (new ResponseResource(true, "Produk dihapus dari list scrap", null))
                    ->response()->setStatusCode(200);
            }

            return (new ResponseResource(false, "Produk tidak ditemukan dalam dokumen ini", null))
                ->response()->setStatusCode(404);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan server: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function finishScrap(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($id);

            if (!$doc) {
                return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            if ($doc->status == 'selesai') {
                return (new ResponseResource(false, "Dokumen ini sudah selesai sebelumnya", null))
                    ->response()->setStatusCode(422);
            }

            if ($doc->total_product == 0) {
                return (new ResponseResource(false, "List scrap kosong! Masukkan produk terlebih dahulu.", null))
                    ->response()->setStatusCode(422);
            }

            New_product::where('scrap_document_id', $id)->update([
                'new_status_product' => 'scrap_qcd'
            ]);

            StagingProduct::where('scrap_document_id', $id)->update([
                'new_status_product' => 'scrap_qcd'
            ]);

            $doc->update([
                'status' => 'selesai',
                'description' => $request->description ?? $doc->description
            ]);

            DB::commit();
            return (new ResponseResource(true, "Scrap Berhasil! Inventory telah diupdate.", $doc))
                ->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Scrap Finish Error: " . $e->getMessage());
            return (new ResponseResource(false, "Gagal menyelesaikan scrap: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    private function recalculateTotals($docId)
    {

        $displayStats = New_product::where('scrap_document_id', $docId)
            ->selectRaw('COUNT(*) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
            ->first();


        $stagingStats = StagingProduct::where('scrap_document_id', $docId)
            ->selectRaw('COUNT(*) as qty, SUM(new_price_product) as new_price, SUM(old_price_product) as old_price')
            ->first();


        $totalQty = ($displayStats->qty ?? 0) + ($stagingStats->qty ?? 0);
        $totalNew = ($displayStats->new_price ?? 0) + ($stagingStats->new_price ?? 0);
        $totalOld = ($displayStats->old_price ?? 0) + ($stagingStats->old_price ?? 0);


        ScrapDocument::where('id', $docId)->update([
            'total_product' => $totalQty,
            'total_new_price' => $totalNew,
            'total_old_price' => $totalOld,
        ]);
    }

    public function show($id)
    {
        $doc = ScrapDocument::with('user:id,name')->find($id);

        if (!$doc) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                ->response()->setStatusCode(404);
        }

        $displayItems = New_product::where('scrap_document_id', $id)->get()->map(function ($item) {
            $item['source'] = 'display';
            return $item;
        });

        $stagingItems = StagingProduct::where('scrap_document_id', $id)->get()->map(function ($item) {
            $item['source'] = 'staging';
            return $item;
        });

        $allItems = $displayItems->merge($stagingItems);

        return (new ResponseResource(true, "Detail Dokumen Scrap", [
            'document' => $doc,
            'items' => $allItems
        ]))->response()->setStatusCode(200);
    }

    public function indexHistory()
    {
        $docs = ScrapDocument::with('user:id,name')
            ->where('status', 'selesai')
            ->latest()
            ->paginate(10);

        return (new ResponseResource(true, "Riwayat Scrap", $docs))
            ->response()->setStatusCode(200);
    }
}
