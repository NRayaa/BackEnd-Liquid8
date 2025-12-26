<?php

namespace App\Http\Controllers;

use App\Exports\ScrapDocumentExport;
use App\Models\ScrapDocument;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ResponseResource;
use App\Models\MigrateBulkyProduct;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

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

    public function getActiveSession(Request $request)
    {
        $user = auth()->user();
        $perPage = $request->query('per_page', 15);

        $doc = ScrapDocument::where('user_id', $user->id)
            ->where('status', 'proses')
            ->first();

        $items = null;
        $message = "";
        $statusCode = 200;

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

            $items = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1);
            $message = "Sesi Scrap Baru Berhasil Dibuat";
            $statusCode = 201;
        } else {
            $this->recalculateTotals($doc->id);
            $doc->refresh();

            $displayQuery = New_product::select([
                'id',
                'new_name_product',
                'new_barcode_product',
                'new_price_product',
                'old_price_product',
                'new_category_product',
                'new_status_product',
                'created_at',
                'updated_at'
            ])
                ->addSelect(DB::raw("'display' as source"))
                ->whereHas('scrapDocuments', function ($q) use ($doc) {
                    $q->where('scrap_document_id', $doc->id);
                });

            $stagingQuery = StagingProduct::select([
                'id',
                'new_name_product',
                'new_barcode_product',
                'new_price_product',
                'old_price_product',
                'new_category_product',
                'new_status_product',
                'created_at',
                'updated_at'
            ])
                ->addSelect(DB::raw("'staging' as source"))
                ->whereHas('scrapDocuments', function ($q) use ($doc) {
                    $q->where('scrap_document_id', $doc->id);
                });

            $migrateQuery = MigrateBulkyProduct::select([
                'id',
                'new_name_product',
                'new_barcode_product',
                'new_price_product',
                'old_price_product',
                'new_category_product',
                'new_status_product',
                'created_at',
                'updated_at'
            ])
                ->addSelect(DB::raw("'migrate' as source"))
                ->whereHas('scrapDocuments', function ($q) use ($doc) {
                    $q->where('scrap_document_id', $doc->id);
                });

            $items = $displayQuery
                ->union($stagingQuery)
                ->union($migrateQuery)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            $message = "Sesi Scrap Aktif Ditemukan";
        }

        return (new ResponseResource(true, $message, [
            'document' => $doc,
            'items' => $items
        ]))->response()->setStatusCode($statusCode);
    }

    public function addProductToScrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scrap_document_id' => 'required|exists:scrap_documents,id',
            'product_id' => 'required',
            'source' => 'required|in:staging,display,migrate'
        ]);

        if ($validator->fails()) return response()->json(['status' => false, 'message' => $validator->errors()], 422);

        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($request->scrap_document_id);
            if ($doc->status !== 'proses') {
                return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menambah produk!", null))
                    ->response()->setStatusCode(422);
            }

            if ($doc->status == 'selesai') {
                return new ResponseResource(false, "Dokumen ini sudah selesai!", null);
            }

            $model = null;
            if ($request->source === 'staging') {
                $model = StagingProduct::class;
            } elseif ($request->source === 'display') {
                $model = New_product::class;
            } elseif ($request->source === 'migrate') {
                $model = MigrateBulkyProduct::class;
            }

            $product = $model::find($request->product_id);

            if (!$product) return new ResponseResource(false, "Produk tidak ditemukan!", null);

            if ($product->new_status_product !== 'dump') {
                return new ResponseResource(false, "Status produk harus dump!", null);
            }

            $isBeingScrapped = $product->scrapDocuments()->where('status', 'proses')->exists();
            if ($isBeingScrapped) {
                return new ResponseResource(false, "Produk sedang dalam keranjang scrap dokumen lain/ini!", null);
            }

            if ($request->source == 'staging') {
                $doc->stagingProducts()->syncWithoutDetaching([$product->id]);
            } elseif ($request->source == 'display') {
                $doc->newProducts()->syncWithoutDetaching([$product->id]);
            } elseif ($request->source == 'migrate') {
                $doc->migrateBulkyProducts()->syncWithoutDetaching([$product->id]);
            }

            $this->recalculateTotals($doc->id);

            DB::commit();
            return new ResponseResource(true, "Produk masuk list scrap", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function show($id)
    {
        $doc = ScrapDocument::with('user:id,name')->find($id);

        if (!$doc) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                ->response()->setStatusCode(404);
        }

        $columns = [
            'id',
            'new_name_product',
            'new_barcode_product',
            'new_price_product',
            'old_price_product',
            'new_category_product',
            'new_status_product',
            'created_at',
            'updated_at'
        ];

        $displayQuery = New_product::select($columns)
            ->addSelect(DB::raw("'display' as source"))
            ->whereHas('scrapDocuments', function ($q) use ($id) {
                $q->where('scrap_document_id', $id);
            });

        $stagingQuery = StagingProduct::select($columns)
            ->addSelect(DB::raw("'staging' as source"))
            ->whereHas('scrapDocuments', function ($q) use ($id) {
                $q->where('scrap_document_id', $id);
            });

        $migrateQuery = MigrateBulkyProduct::select($columns)
            ->addSelect(DB::raw("'migrate' as source"))
            ->whereHas('scrapDocuments', function ($q) use ($id) {
                $q->where('scrap_document_id', $id);
            });

        $allItems = $displayQuery
            ->union($stagingQuery)
            ->union($migrateQuery)
            ->orderBy('updated_at', 'desc')
            ->get();

        return (new ResponseResource(true, "Detail Dokumen Scrap", [
            'document' => $doc,
            'items' => $allItems
        ]))->response()->setStatusCode(200);
    }

    public function removeProductFromScrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scrap_document_id' => 'required',
            'product_id' => 'required',
            'source' => 'required|in:staging,display,migrate'
        ]);

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($request->scrap_document_id);

            if ($doc->status !== 'proses') {
                return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menghapus produk!", null))
                    ->response()->setStatusCode(422);
            }

            if ($request->source == 'staging') {
                $doc->stagingProducts()->detach($request->product_id);
            } else if ($request->source == 'display') {
                $doc->newProducts()->detach($request->product_id);
            } else {
                $doc->migrateBulkyProducts()->detach($request->product_id);
            }

            $this->recalculateTotals($doc->id);

            DB::commit();
            return new ResponseResource(true, "Produk dihapus dari list scrap", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function addAllDumpToCart(Request $request)
    {
        $docId = $request->scrap_document_id;
        $doc = ScrapDocument::find($docId);

        if ($doc->status !== 'proses') {
            return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menambah produk!", null))
                ->response()->setStatusCode(422);
        }

        if (!$doc || $doc->status == 'selesai') return new ResponseResource(false, "Dokumen invalid", null);

        DB::beginTransaction();
        try {
            $displayIds = New_product::where('new_status_product', 'dump')
                ->whereDoesntHave('scrapDocuments')
                ->pluck('id');

            if ($displayIds->isNotEmpty()) {
                $doc->newProducts()->syncWithoutDetaching($displayIds);
            }

            $stagingIds = StagingProduct::where('new_status_product', 'dump')
                ->whereDoesntHave('scrapDocuments')
                ->pluck('id');

            if ($stagingIds->isNotEmpty()) {
                $doc->stagingProducts()->syncWithoutDetaching($stagingIds);
            }

            $migrateIds = MigrateBulkyProduct::where('new_status_product', 'dump')
                ->whereDoesntHave('scrapDocuments')
                ->pluck('id');

            if ($migrateIds->isNotEmpty()) {
                $doc->migrateBulkyProducts()->syncWithoutDetaching($migrateIds);
            }

            $total = $displayIds->count() + $stagingIds->count() + $migrateIds->count();

            if ($total > 0) {
                $this->recalculateTotals($docId);
                DB::commit();
                return new ResponseResource(true, "$total produk masuk keranjang", null);
            }

            return new ResponseResource(false, "Tidak ada produk dump tersedia", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function finishScrap($id)
    {
        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($id);

            if (!$doc) return new ResponseResource(false, "Dokumen invalid", null);

            if (!in_array($doc->status, ['proses', 'lock'])) {
                return (new ResponseResource(false, "Dokumen sudah selesai sebelumnya.", null))
                    ->response()->setStatusCode(422);
            }

            if ($doc->total_product == 0) return new ResponseResource(false, "List kosong", null);

            $doc->newProducts()->update(['new_status_product' => 'scrap_qcd']);
            $doc->stagingProducts()->update(['new_status_product' => 'scrap_qcd']);
            $doc->migrateBulkyProducts()->update(['new_status_product' => 'scrap_qcd']);

            $doc->update([
                'status' => 'selesai',
            ]);

            DB::commit();
            return new ResponseResource(true, "Scrap Selesai.", $doc);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal finish: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function lockSession($id)
    {
        DB::beginTransaction();
        try {
            $doc = ScrapDocument::find($id);

            if (!$doc) {
                return (new ResponseResource(false, "Dokumen tidak ditemukan", null))->response()->setStatusCode(404);
            }

            if ($doc->status !== 'proses') {
                return (new ResponseResource(false, "Gagal! Dokumen sudah terkunci atau selesai.", null))
                    ->response()->setStatusCode(422);
            }

            if ($doc->total_product == 0) {
                return (new ResponseResource(false, "List kosong! Masukkan produk sebelum menyelesaikan input.", null))
                    ->response()->setStatusCode(422);
            }

            $doc->update([
                'status' => 'lock'
            ]);

            DB::commit();
            return new ResponseResource(true, "Input Produk Selesai. Dokumen terkunci menunggu eksekusi.", $doc);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Error: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    private function recalculateTotals($docId)
    {
        $doc = ScrapDocument::withCount([
            'newProducts',
            'stagingProducts',
            'migrateBulkyProducts'
        ])->find($docId);

        $qtyDisplay = $doc->new_products_count ?? 0;
        $valDisplayNew = $doc->newProducts()->sum('new_price_product');
        $valDisplayOld = $doc->newProducts()->sum('old_price_product');

        $qtyStaging = $doc->staging_products_count ?? 0;
        $valStagingNew = $doc->stagingProducts()->sum('new_price_product');
        $valStagingOld = $doc->stagingProducts()->sum('old_price_product');

        $qtyMigrate = $doc->migrate_bulky_products_count ?? 0;
        $valMigrateNew = $doc->migrateBulkyProducts()->sum('new_price_product');
        $valMigrateOld = $doc->migrateBulkyProducts()->sum('old_price_product');

        $doc->update([
            'total_product' => $qtyDisplay + $qtyStaging + $qtyMigrate,
            'total_new_price' => $valDisplayNew + $valStagingNew + $valMigrateNew,
            'total_old_price' => $valDisplayOld + $valStagingOld + $valMigrateOld,
        ]);
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

    public function exportQCD($id)
    {
        try {
            $doc = ScrapDocument::find($id);
            if (!$doc) {
                return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            $folderName = 'exports/scrap_documents';

            $fileName = 'QCD_' . str_replace(['/', '\\', ' '], '-', $doc->code_document_scrap) . '.xlsx';

            $filePath = $folderName . '/' . $fileName;

            Excel::store(new ScrapDocumentExport($id), $filePath, 'public_direct');

            $downloadUrl = url($filePath);

            return (new ResponseResource(true, "File berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name' => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }
}
