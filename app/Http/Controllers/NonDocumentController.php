<?php

namespace App\Http\Controllers;

use App\Exports\NonDocumentExport;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Models\MigrateBulkyProduct;
use App\Http\Resources\ResponseResource;
use App\Models\NonDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class NonDocumentController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $status = $request->query('status');
        $perPage = $request->query('per_page', 15);

        $query = NonDocument::with('user:id,name')->latest();

        if ($q) {
            $query->where(function ($subQuery) use ($q) {
                // 1. Cari berdasarkan Kode Dokumen
                $subQuery->where('code_document_non', 'LIKE', '%' . $q . '%')

                    // 2. Cari berdasarkan Nama User
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery->where('name', 'LIKE', '%' . $q . '%');
                    })

                    // 3. Deep Search: Cari Barcode di Produk Display
                    ->orWhereHas('newProducts', function ($prodQuery) use ($q) {
                        $prodQuery->where('new_barcode_product', 'LIKE', '%' . $q . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $q . '%');
                    })

                    // 4. Deep Search: Cari Barcode di Produk Staging
                    ->orWhereHas('stagingProducts', function ($prodQuery) use ($q) {
                        $prodQuery->where('new_barcode_product', 'LIKE', '%' . $q . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $q . '%');
                    })

                    // 5. Deep Search: Cari Barcode di Produk Migrate
                    ->orWhereHas('migrateBulkyProducts', function ($prodQuery) use ($q) {
                        $prodQuery->where('new_barcode_product', 'LIKE', '%' . $q . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $q . '%');
                    });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $documents = $query->paginate($perPage);

        return (new ResponseResource(true, "List Data Non Documents", $documents))
            ->response()->setStatusCode(200);
    }

    public function getActiveSession(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }

        $perPage = $request->query('per_page', 15);

        $doc = NonDocument::where('user_id', $user->id)
            ->where('status', 'proses')
            ->first();

        $items = null;
        $message = "";
        $statusCode = 200;

        if (!$doc) {
            $now = now();
            $month = $now->format('m');
            $year = $now->format('Y');
            $monthYear = $month . '/' . $year;

            $lastDoc = NonDocument::where('code_document_non', 'LIKE', '%/NON/' . $monthYear)
                ->latest('id')
                ->first();

            $nextNumber = 1;
            if ($lastDoc) {
                $lastCode = $lastDoc->code_document_non;
                preg_match('/^(\d+)\//', $lastCode, $matches);
                if (isset($matches[1])) {
                    $nextNumber = (int)$matches[1] + 1;
                }
            }

            $code = str_pad($nextNumber, 4, '0', STR_PAD_LEFT) . '/NON/' . $monthYear;

            $doc = NonDocument::create([
                'code_document_non' => $code,
                'user_id' => $user->id,
                'status' => 'proses',
            ]);

            $items = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1);
            $message = "Sesi Non Baru Berhasil Dibuat";
            $statusCode = 201;
        } else {
            $this->recalculateTotals($doc->id);
            $doc->refresh();

            $columns = [
                'id',
                'new_name_product',
                'new_barcode_product',
                'new_price_product',
                'old_price_product',
                'new_category_product',
                'new_status_product',
                'new_quality',
                'is_so',
                'user_so',
                'created_at',
                'updated_at'
            ];

            // 1. Display Query
            $displayQuery = New_product::select($columns)
                ->addSelect(DB::raw("'display' as source"))
                ->whereHas('nonDocuments', function ($q) use ($doc) {
                    $q->where('non_documents.id', $doc->id);
                });

            // 2. Staging Query
            $stagingQuery = StagingProduct::select($columns)
                ->addSelect(DB::raw("'staging' as source"))
                ->whereHas('nonDocuments', function ($q) use ($doc) {
                    $q->where('non_documents.id', $doc->id);
                });

            // 3. Migrate Query
            $migrateQuery = MigrateBulkyProduct::select($columns)
                ->addSelect(DB::raw("'migrate' as source"))
                ->whereHas('nonDocuments', function ($q) use ($doc) {
                    $q->where('non_documents.id', $doc->id);
                });

            // Union & Pagination
            $items = $displayQuery
                ->union($stagingQuery)
                ->union($migrateQuery)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            $items->getCollection()->transform(function ($item) {
                $item->status_so = ($item->is_so === 'done') ? 'Sudah SO' : 'Belum SO';
                return $item;
            });

            $message = "Sesi Non Aktif Ditemukan";
        }

        return (new ResponseResource(true, $message, [
            'document' => $doc,
            'items' => $items
        ]))->response()->setStatusCode($statusCode);
    }

    public function addProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'non_document_id' => 'required|exists:non_documents,id',
            'product_id' => 'required',
            'source' => 'required|in:staging,display,migrate'
        ]);

        if ($validator->fails()) return response()->json(['status' => false, 'message' => $validator->errors()], 422);

        DB::beginTransaction();
        try {
            $doc = NonDocument::find($request->non_document_id);
            if ($doc->status !== 'proses') {
                return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menambah produk!", null))
                    ->response()->setStatusCode(422);
            }

            $model = match ($request->source) {
                'staging' => StagingProduct::class,
                'display' => New_product::class,
                'migrate' => MigrateBulkyProduct::class,
            };

            $product = $model::find($request->product_id);
            if (!$product) return new ResponseResource(false, "Produk tidak ditemukan!", null);

            $forbiddenStatuses = ['migrate', 'sale', 'dump', 'scrap_qcd'];

            if (in_array($product->new_status_product, $forbiddenStatuses)) {
                return (new ResponseResource(false, "Gagal! Status produk tidak valid untuk Non. (Status saat ini: " . $product->new_status_product . ")", null))
                    ->response()->setStatusCode(422);
            }

            $quality = json_decode($product->new_quality, true) ?? [];
            if (!isset($quality['non'])) {
                return (new ResponseResource(false, "Gagal! Produk ini tidak memiliki keterangan quality non.", null))
                    ->response()->setStatusCode(422);
            }

            // Validasi 2: Cek Duplikasi di Dokumen Lain
            if ($product->nonDocuments()->exists()) {
                return (new ResponseResource(false, "Produk ini sudah terdaftar di dokumen non (Proses/Selesai)!", null))
                    ->response()->setStatusCode(422);
            }

            // Sync Relasi (Polymorphic)
            if ($request->source == 'staging') {
                $doc->stagingProducts()->syncWithoutDetaching([$product->id]);
            } elseif ($request->source == 'display') {
                $doc->newProducts()->syncWithoutDetaching([$product->id]);
            } else {
                $doc->migrateBulkyProducts()->syncWithoutDetaching([$product->id]);
            }

            $this->recalculateTotals($doc->id);

            DB::commit();
            return new ResponseResource(true, "Produk masuk list Non", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function addAllToCart(Request $request)
    {
        set_time_limit(300);

        $docId = $request->non_document_id;

        $doc = NonDocument::find($docId);

        if (!$doc) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan/invalid", null))
                ->response()->setStatusCode(404);
        }

        if ($doc->status !== 'proses') {
            return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menambah produk!", null))
                ->response()->setStatusCode(422);
        }

        if (!$doc || $doc->status == 'selesai') return new ResponseResource(false, "Dokumen invalid", null);

        DB::beginTransaction();
        try {
            $totalAdded = 0;
            $chunkSize = 100;

            $forbiddenStatuses = ['migrate', 'sale', 'dump', 'scrap_qcd'];

            New_product::whereNotIn('new_status_product', $forbiddenStatuses)
                ->whereNotNull('new_quality->non')
                ->where('new_quality->non', '!=', '')
                ->whereDoesntHave('nonDocuments')
                ->chunkById($chunkSize, function ($products) use ($doc, &$totalAdded) {
                    $ids = $products->pluck('id')->toArray();
                    if (!empty($ids)) {
                        $doc->newProducts()->syncWithoutDetaching($ids);
                        $totalAdded += count($ids);
                    }
                });

            StagingProduct::whereNotIn('new_status_product', $forbiddenStatuses)
                ->whereNotNull('new_quality->non')
                ->where('new_quality->non', '!=', '')
                ->whereDoesntHave('nonDocuments')
                ->chunkById($chunkSize, function ($products) use ($doc, &$totalAdded) {
                    $ids = $products->pluck('id')->toArray();
                    if (!empty($ids)) {
                        $doc->stagingProducts()->syncWithoutDetaching($ids);
                        $totalAdded += count($ids);
                    }
                });

            MigrateBulkyProduct::whereNotIn('new_status_product', $forbiddenStatuses)
                ->whereNotNull('new_quality->non')
                ->where('new_quality->non', '!=', '')
                ->whereDoesntHave('nonDocuments')
                ->chunkById($chunkSize, function ($products) use ($doc, &$totalAdded) {
                    $ids = $products->pluck('id')->toArray();
                    if (!empty($ids)) {
                        $doc->migrateBulkyProducts()->syncWithoutDetaching($ids);
                        $totalAdded += count($ids);
                    }
                });

            if ($totalAdded > 0) {
                $this->recalculateTotals($docId);
                DB::commit();
                return new ResponseResource(true, "$totalAdded produk (Display/Expired) dengan quality 'Non' masuk keranjang.", null);
            }

            DB::commit();
            return new ResponseResource(false, "Tidak ada produk Display/Expired dengan quality 'Non' tersedia", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function show(Request $request, $id)
    {
        $doc = NonDocument::with('user:id,name')->find($id);
        $perPage = $request->query('per_page', 15);
        $search = $request->query('q');

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
            'new_quality',
            'created_at',
            'updated_at',
            'is_so',
            'user_so'
        ];

        $displayQuery = New_product::select($columns)->addSelect(DB::raw("'display' as source"))
            ->whereHas('nonDocuments', function ($q) use ($id) {
                $q->where('non_document_id', $id);
            });

        $stagingQuery = StagingProduct::select($columns)->addSelect(DB::raw("'staging' as source"))
            ->whereHas('nonDocuments', function ($q) use ($id) {
                $q->where('non_document_id', $id);
            });

        $migrateQuery = MigrateBulkyProduct::select($columns)->addSelect(DB::raw("'migrate' as source"))
            ->whereHas('nonDocuments', function ($q) use ($id) {
                $q->where('non_document_id', $id);
            });

        if ($search) {
            $applySearch = function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'LIKE', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $search . '%');
                });
            };

            $applySearch($displayQuery);
            $applySearch($stagingQuery);
            $applySearch($migrateQuery);
        }

        $allItems = $displayQuery
            ->union($stagingQuery)
            ->union($migrateQuery)
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        $allItems->getCollection()->transform(function ($item) {
            $item->status_so = ($item->is_so === 'done') ? 'Sudah SO' : 'Belum SO';
            return $item;
        });

        return (new ResponseResource(true, "Detail Dokumen Non", [
            'document' => $doc,
            'items' => $allItems
        ]))->response()->setStatusCode(200);
    }

    public function removeProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'non_document_id' => 'required',
            'product_id' => 'required',
            'source' => 'required|in:staging,display,migrate'
        ]);

        if ($validator->fails()) return response()->json($validator->errors(), 422);

        DB::beginTransaction();
        try {
            $doc = NonDocument::find($request->non_document_id);

            if ($doc->status !== 'proses') {
                return (new ResponseResource(false, "Dokumen terkunci/selesai. Tidak bisa menghapus produk!", null))
                    ->response()->setStatusCode(422);
            }

            if ($request->source == 'staging') {
                $doc->stagingProducts()->detach($request->product_id);
            } elseif ($request->source == 'display') {
                $doc->newProducts()->detach($request->product_id);
            } else {
                $doc->migrateBulkyProducts()->detach($request->product_id);
            }

            $this->recalculateTotals($doc->id);

            DB::commit();
            return new ResponseResource(true, "Produk dihapus dari list non", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error: " . $e->getMessage(), null);
        }
    }

    public function finish(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $doc = NonDocument::find($id);

            if (!$doc) return new ResponseResource(false, "Dokumen invalid", null);

            if (!in_array($doc->status, ['proses', 'lock'])) {
                return (new ResponseResource(false, "Dokumen sudah selesai sebelumnya.", null))
                    ->response()->setStatusCode(422);
            }

            if ($doc->total_product == 0) return new ResponseResource(false, "List kosong", null);

            $doc->update([
                'status' => 'selesai',
            ]);

            DB::commit();
            return new ResponseResource(true, "Proses Non Selesai.", $doc);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal finish: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function lockSession($id)
    {
        DB::beginTransaction();
        try {
            $doc = NonDocument::find($id);

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
        // Menggunakan withCount untuk menghitung relasi polymorphic
        $doc = NonDocument::withCount([
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

    public function exportNon($id)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        
        try {
            $doc = NonDocument::find($id);
            if (!$doc) {
                return (new ResponseResource(false, "Dokumen tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            $folderName = 'exports/non_documents';
            $fileName = 'NON_' . str_replace(['/', '\\', ' '], '-', $doc->code_document_non) . '.xlsx';
            $filePath = $folderName . '/' . $fileName;

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new NonDocumentExport($id), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return (new ResponseResource(true, "File berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name' => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function exportAllProductsNon()
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $folderName = 'exports/non_documents';
            $fileName = 'All_Non_' . date('Ymd_His') . '.xlsx';
            $filePath = $folderName . '/' . $fileName;

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new \App\Exports\AllNonProductDocumentExport(), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

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
