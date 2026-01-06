<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BulkySale;
use App\Models\Document;
use App\Models\FilterStaging;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\Product_Bundle;
use App\Models\Product_old;
use App\Models\ProductDefect;
use App\Models\RepairFilter;
use App\Models\RepairProduct;
use App\Models\RiwayatCheck;
use App\Models\Sale;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');
        $status = $request->input('f');

        $documents = Document::latest();

        if ($query) {
            $documents->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('code_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%');
            });
        }
        if ($status) {
            $documents->where('status_document', 'LIKE', '%' . $status . '%');
        }
        $paginated = $documents->paginate(50);

        return new ResponseResource(true, "List Documents", $paginated);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show(Document $document)
    {
        return new ResponseResource(true, "detail document", $document);
    }

    public function edit(Document $document)
    {
        //
    }

    public function update(Request $request, Document $document)
    {
        //
    }

    public function destroy(Document $document, Request $request)
    {
        $user = auth()->user()->email;
        try {
            $product_old = Product_old::where('code_document', $document->code_document)->delete();
            $approve = ProductApprove::where('code_document', $document->code_document)->delete();
            $document->delete();

            logUserAction($request, $request->user(), "inbound/check_product/list_data", "code document " . $document->code_document . "name" . $document->base_document . " deleted by " . $user);

            return new ResponseResource(true, "data berhasil dihapus", $document);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }

    public function deleteAll()
    {
        try {
            Document::truncate();
            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }

    public function documentInProgress(Request $request)
    {
        $query = $request->input('q');
        $status = $request->input('f');

        $documents = Document::latest();

        if (!empty($query)) {
            $documents = $documents->where(function ($search) use ($query) {
                $search->where('status_document', '!=', 'pending')
                    ->where(function ($baseCode) use ($query) {
                        $baseCode->where('base_document', 'LIKE', '%' . $query . '%')
                            ->orWhere('status_document', $query)
                            ->orWhere('code_document', 'LIKE', '%' . $query . '%');
                    });
            });
        }

        if (!empty($status)) {
            $documents->where('status_document', 'LIKE', '%' . $status . '%');
        }

        if (empty($query) && empty($status)) {
            $documents = $documents->where('status_document', '!=', 'pending');
        }

        return new ResponseResource(true, "List document progress", $documents->paginate(30));
    }

    public function documentDone(Request $request) // halaman list product staging by doc
    {
        $query = $request->input('q');

        $documents = Document::latest()->where('status_document', 'done');

        // Jika query pencarian tidak kosong, tambahkan kondisi pencarian
        if (!empty($query)) {
            $documents = $documents->where(function ($search) use ($query) {
                $search->where(function ($baseCode) use ($query) {
                    $baseCode->where('base_document', 'LIKE', '%' . $query . '%')
                        ->orWhere('code_document', 'LIKE', '%' . $query . '%');
                });
            });
        }

        // Mengembalikan hasil dalam bentuk paginasi
        return new ResponseResource(true, "list document progress", $documents->paginate(50));
    }

    private function changeBarcodeByDocument($code_document, $init_barcode)
    {
        DB::beginTransaction();
        try {
            $document = Document::where('code_document', $code_document)->first();
            $document->custom_barcode = $init_barcode;
            $document->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating barcodes: ' . $e->getMessage());
            return false;
        }
    }

    public function changeBarcodeDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'init_barcode' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $generate = $this->changeBarcodeByDocument($request->code_document, $request->init_barcode);

        if ($generate) {
            return new ResponseResource(true, "berhasil mengganti barcode", $request->init_barcode);
        } else {
            return "gagal";
        }
    }

    public function deleteCustomBarcode(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), ['code_document' => 'required']);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $document = Document::where('code_document', $request->input('code_document'))->first();
            $document->update(['custom_barcode' => null]);
            return new ResponseResource(true, "custom barcode dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "gagal di hapus", $e->getMessage());
        }
    }

    public function checkDocumentOnGoing(Request $request)
    {
        $documents = Document::where('status_document', 'pending')->orWhere('status_document', 'in progress')->latest()->paginate(50);
        return new ResponseResource(true, "list docs", $documents);
    }

    public function findDataDocs(Request $request, $code_document)
    {
        $userId = auth()->id();
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();
        $document = Document::where('code_document', $code_document)->first();

        $discrepancy = Product_old::where('code_document', $code_document)->select('id', 'old_price_product')->get();

        // Optimasi: Hitung count dan sum sekaligus untuk setiap tabel
        $inventoryStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price
            ')
            ->first();

        $inventoryLolosStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $inventoryDamagedStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $inventoryAbnormalStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        // Tambahkan statistik untuk kualitas baru: non
        $inventoryNonStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->non', '!=', null)
            ->selectRaw('COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price')
            ->first();

        $stagingStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $stagingLolosStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $stagingDamagedStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $stagingAbnormalStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $stagingNonStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->non', '!=', null)
            ->selectRaw('COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price')
            ->first();

        $productBundleStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $productBundleLolosStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $productBundleDamagedStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $productBundleAbnormalStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $productBundleNonStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->non', '!=', null)
            ->selectRaw('COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price')
            ->first();

        $productApproveStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $productApproveLolosStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $productApproveDamagedStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $productApproveAbnormalStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $productApproveNonStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->non', '!=', null)
            ->selectRaw('COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price')
            ->first();

        $repairProductStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $repairProductLolosStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $repairProductDamagedStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $repairProductAbnormalStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $repairProductNonStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->non', '!=', null)
            ->selectRaw('COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price')
            ->first();

        $salesStats = Sale::where('code_document', $code_document)
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_product_old_price_sale, product_old_price_sale)) as total_price')
            ->first();

        // Tambahkan data abnormal dari sales dengan kondisi khusus
        $salesAbnormalStats = null;
        if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
            $salesAbnormalStats = Sale::where('code_document', $code_document)
                ->where('status_product', 'abnormal')
                ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_product_old_price_sale, product_old_price_sale)) as abnormal_price')
                ->first();
        }
        //b2b
        $b2bStats = BulkySale::where('code_document', $code_document)
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(COALESCE(old_price_bulky_sale, actual_old_price_product)) as total_price
            ')
            ->first();

        $b2bLolosStats = BulkySale::where('code_document', $code_document)
            ->where('status_product_before', 'display')
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(old_price_bulky_sale, actual_old_price_product)) as lolos_price')
            ->first();


        $b2bAbnormalStats = BulkySale::where('code_document', $code_document)
            ->where('status_product_before', 'abnormal')
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(old_price_bulky_sale, actual_old_price_product)) as abnormal_price')
            ->first();


        // Hitung total dari semua tabel
        $allData = ($inventoryStats->total_count ?? 0) + ($stagingStats->total_count ?? 0) +
            ($productBundleStats->total_count ?? 0) + ($productApproveStats->total_count ?? 0) +
            ($repairProductStats->total_count ?? 0) +
            ($salesStats->total_count ?? 0) + ($b2bStats->total_count ?? 0);

        // Hitung total price dari semua tabel menggunakan stats yang sudah dioptimasi
        $totalInventoryPrice = $inventoryStats->total_price ?? 0;
        $totalStagingsPrice = $stagingStats->total_price ?? 0;
        $totalProductBundlePrice = $productBundleStats->total_price ?? 0;
        $totalSalesPrice = $salesStats->total_price ?? 0;
        $totalProductApprovePrice = $productApproveStats->total_price ?? 0;
        $totalRepairProductPrice = $repairProductStats->total_price ?? 0;
        $totalDiscrepancyPrice = $discrepancy->sum('old_price_product');
        $totalB2bPrice = $b2bStats->total_price ?? 0;

        // Jumlahkan semua total harga
        $totalPrice = $totalInventoryPrice + $totalStagingsPrice + $totalProductBundlePrice +
            $totalSalesPrice + $totalProductApprovePrice +
            $totalRepairProductPrice +
            $totalDiscrepancyPrice + $totalB2bPrice;

        // total price in
        $totalPriceIn = $totalInventoryPrice + $totalStagingsPrice + $totalProductBundlePrice +
            $totalSalesPrice + $totalProductApprovePrice +
            $totalRepairProductPrice + $totalB2bPrice;

        // Hitung count dan price untuk lolos, damaged, abnormal dari stats yang sudah diperbaiki
        $countDataLolos = ($inventoryLolosStats->lolos_count ?? 0) + ($stagingLolosStats->lolos_count ?? 0) +
            ($productBundleLolosStats->lolos_count ?? 0) + ($productApproveLolosStats->lolos_count ?? 0) +
            ($repairProductLolosStats->lolos_count ?? 0) +
            ($salesStats->total_count ?? 0) +
            ($b2bLolosStats->lolos_count ?? 0);

        $lolosPrice = ($inventoryLolosStats->lolos_price ?? 0) + ($stagingLolosStats->lolos_price ?? 0) +
            ($productBundleLolosStats->lolos_price ?? 0) + ($productApproveLolosStats->lolos_price ?? 0) +
            ($repairProductLolosStats->lolos_price ?? 0) +
            ($salesStats->total_price ?? 0) + ($b2bLolosStats->lolos_price ?? 0);

        $countDataDamaged = ($inventoryDamagedStats->damaged_count ?? 0) + ($stagingDamagedStats->damaged_count ?? 0) +
            ($productBundleDamagedStats->damaged_count ?? 0) + ($productApproveDamagedStats->damaged_count ?? 0) +
            ($repairProductDamagedStats->damaged_count ?? 0);

        $damagedPrice = ($inventoryDamagedStats->damaged_price ?? 0) + ($stagingDamagedStats->damaged_price ?? 0) +
            ($productBundleDamagedStats->damaged_price ?? 0) + ($productApproveDamagedStats->damaged_price ?? 0) +
            ($repairProductDamagedStats->damaged_price ?? 0);
        //tambahkan data abnormal dari sales, ambil dari  sales * where status_product = 'abnormal'
        // kecuali code_document yang  0553/09/2025 dan 0555/09/2025
        $countDataAbnormal = ($inventoryAbnormalStats->abnormal_count ?? 0) + ($stagingAbnormalStats->abnormal_count ?? 0) +
            ($productBundleAbnormalStats->abnormal_count ?? 0) + ($productApproveAbnormalStats->abnormal_count ?? 0) +
            ($repairProductAbnormalStats->abnormal_count ?? 0) +
            ($salesAbnormalStats->abnormal_count ?? 0) +
            ($b2bAbnormalStats->abnormal_count ?? 0);

        $abnormalPrice = ($inventoryAbnormalStats->abnormal_price ?? 0) + ($stagingAbnormalStats->abnormal_price ?? 0) +
            ($productBundleAbnormalStats->abnormal_price ?? 0) + ($productApproveAbnormalStats->abnormal_price ?? 0) +
            ($repairProductAbnormalStats->abnormal_price ?? 0) +
            ($salesAbnormalStats->abnormal_price ?? 0) + ($b2bAbnormalStats->abnormal_price ?? 0);

        // Hitung count dan price untuk kategori 'non'
        $countDataNon = ($inventoryNonStats->non_count ?? 0) + ($stagingNonStats->non_count ?? 0) +
            ($productBundleNonStats->non_count ?? 0) + ($productApproveNonStats->non_count ?? 0) +
            ($repairProductNonStats->non_count ?? 0);

        $nonPrice = ($inventoryNonStats->non_price ?? 0) + ($stagingNonStats->non_price ?? 0) +
            ($productBundleNonStats->non_price ?? 0) + ($productApproveNonStats->non_price ?? 0) +
            ($repairProductNonStats->non_price ?? 0);


        // Inisialisasi collections untuk data yang akan diinsert
        $damagedProducts = collect();
        $abnormalProducts = collect();

        $riwayatCheck = RiwayatCheck::where('code_document', $code_document)->first();

        // dd($riwayatCheck);
        if ($riwayatCheck === null) {
            $riwayatCheck = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $code_document,
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => 0,
                'total_data_lolos' => 0,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'done',
                // Persentase (perbaiki typo: precentage -> percentage)
                'percentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => 0,
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,
                'total_price' => $totalPrice,
                'value_data_lolos' => 0,
                'value_data_damaged' => 0,
                'value_data_abnormal' => 0,
                'value_data_discrepancy' => 0,
                'status_file' => 0,
            ]);
        }

        if ($riwayatCheck && ($riwayatCheck->status_file == null || $riwayatCheck->status_file == 0)) {
            // Optimasi: Ambil data damaged dan abnormal hanya ketika akan insert
            $damagedQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
            ];

            $abnormalQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
            ];

            // Tambahkan sales abnormal query jika tidak termasuk dalam exception code_document
            if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                $abnormalQueries[] = Sale::where('code_document', $code_document)
                    ->where('status_product', 'abnormal')
                    ->select('old_barcode_product', 'product_barcode_sale as new_barcode_product', 'actual_product_old_price_sale as actual_old_price_product');
            }

            // Execute queries untuk damaged
            foreach ($damagedQueries as $query) {
                $damagedProducts = $damagedProducts->merge(
                    $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                );
            }

            // Execute queries untuk abnormal  
            foreach ($abnormalQueries as $index => $query) {
                // Cek apakah ini query untuk Sales (yang terakhir dalam array jika ada)
                if ($index === count($abnormalQueries) - 1 && !in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                    // Ini adalah Sales query, sudah ada select yang benar
                    $abnormalProducts = $abnormalProducts->merge($query->get());
                } else {
                    // Ini adalah query untuk tabel lain
                    $abnormalProducts = $abnormalProducts->merge(
                        $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                    );
                }
            }

            $riwayatCheck->update([
                'total_data_in' => $allData,
                'total_data_lolos' => $countDataLolos,
                'total_data_damaged' => $countDataDamaged,
                'total_data_abnormal' => $countDataAbnormal,
                'total_discrepancy' => count($discrepancy),
                'total_price' => $totalPrice,
                'total_price_in' => $totalPriceIn,
                // Persentase
                'precentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalPriceIn / $totalPrice) * 100,
                'percentage_lolos' => ($countDataLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($countDataDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($countDataAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($discrepancy) / $document->total_column_in_document) * 100,
                'value_data_lolos' => $lolosPrice,
                'value_data_damaged' => $damagedPrice,
                'value_data_abnormal' => $abnormalPrice,
                'value_data_discrepancy' => $discrepancy->sum('old_price_product'),
                'status_file' => 1,
            ]);

            // Get existing products untuk optimasi query (hanya dari damaged dan abnormal non-sales)
            $allBarcodes = collect($damagedProducts)->pluck('new_barcode_product')
                ->merge(collect($abnormalProducts)->pluck('new_barcode_product'))
                ->filter()
                ->unique()
                ->values();

            // Note: Sales abnormal barcodes tidak perlu dicek karena tidak diinsert ke ProductDefect

            // Ambil existing records sebagai collection untuk memudahkan lookup
            $existingProducts = ProductDefect::whereIn('new_barcode_product', $allBarcodes)
                ->get()
                ->keyBy('new_barcode_product');

            // Insert atau Update data damaged ke ProductDefect
            foreach ($damagedProducts as $damaged) {
                // Skip jika barcode null
                if (empty($damaged->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($damaged->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($damaged->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $damaged->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $damaged->old_barcode_product,
                        'old_price_product' => $damaged->actual_old_price_product,
                        'new_barcode_product' => $damaged->new_barcode_product,
                        'type' => 'damaged',
                    ]);
                }
            }

            // Insert atau Update data abnormal ke ProductDefect
            foreach ($abnormalProducts as $abnormal) {
                // Skip jika barcode null
                if (empty($abnormal->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($abnormal->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($abnormal->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $abnormal->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $abnormal->old_barcode_product,
                        'old_price_product' => $abnormal->actual_old_price_product,
                        'new_barcode_product' => $abnormal->new_barcode_product,
                        'type' => 'abnormal',
                    ]);
                }
            }

            // Note: Sales abnormal tidak perlu diinsert ke ProductDefect
            // Cukup dijadikan kalkulasi saja dalam value_data_abnormal dan percentage_abnormal
        }

        if ($riwayatCheck && ($riwayatCheck->status_file == 1)) {
            // Optimasi: Ambil data damaged dan abnormal hanya ketika akan insert
            $damagedQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
            ];

            $abnormalQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
            ];

            // Tambahkan sales abnormal query jika tidak termasuk dalam exception code_document
            if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                $abnormalQueries[] = Sale::where('code_document', $code_document)
                    ->where('status_product', 'abnormal')
                    ->select('old_barcode_product', 'product_barcode_sale as new_barcode_product', 'actual_product_old_price_sale as actual_old_price_product');
            }

            // Execute queries untuk damaged
            foreach ($damagedQueries as $query) {
                $damagedProducts = $damagedProducts->merge(
                    $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                );
            }

            // Execute queries untuk abnormal  
            foreach ($abnormalQueries as $index => $query) {
                // Cek apakah ini query untuk Sales (yang terakhir dalam array jika ada)
                if ($index === count($abnormalQueries) - 1 && !in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                    // Ini adalah Sales query, sudah ada select yang benar
                    $abnormalProducts = $abnormalProducts->merge($query->get());
                } else {
                    // Ini adalah query untuk tabel lain
                    $abnormalProducts = $abnormalProducts->merge(
                        $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                    );
                }
            }

            $riwayatCheck->update([
                'total_data_in' => $allData,
                'total_data_lolos' => $countDataLolos,
                'total_data_damaged' => $countDataDamaged,
                'total_data_abnormal' => $countDataAbnormal,
                'total_discrepancy' => count($discrepancy),
                // 'total_price' => $totalPrice,
                'total_price_in' => $totalPriceIn,
                // Persentase
                'precentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalPriceIn / $totalPrice) * 100,
                'percentage_lolos' => ($countDataLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($countDataDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($countDataAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($discrepancy) / $document->total_column_in_document) * 100,
                'value_data_lolos' => $lolosPrice,
                'value_data_damaged' => $damagedPrice,
                'value_data_abnormal' => $abnormalPrice,
                'value_data_discrepancy' => $discrepancy->sum('old_price_product'),
                // 'status_file' => 1,
            ]);

            // Get existing products untuk optimasi query (hanya dari damaged dan abnormal non-sales)
            $allBarcodes = collect($damagedProducts)->pluck('new_barcode_product')
                ->merge(collect($abnormalProducts)->pluck('new_barcode_product'))
                ->filter()
                ->unique()
                ->values();

            // Note: Sales abnormal barcodes tidak perlu dicek karena tidak diinsert ke ProductDefect

            // Ambil existing records sebagai collection untuk memudahkan lookup
            $existingProducts = ProductDefect::whereIn('new_barcode_product', $allBarcodes)
                ->get()
                ->keyBy('new_barcode_product');

            // Insert atau Update data damaged ke ProductDefect
            foreach ($damagedProducts as $damaged) {
                // Skip jika barcode null
                if (empty($damaged->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($damaged->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($damaged->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $damaged->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $damaged->old_barcode_product,
                        'old_price_product' => $damaged->actual_old_price_product,
                        'new_barcode_product' => $damaged->new_barcode_product,
                        'type' => 'damaged',
                    ]);
                }
            }

            // Insert atau Update data abnormal ke ProductDefect
            foreach ($abnormalProducts as $abnormal) {
                // Skip jika barcode null
                if (empty($abnormal->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($abnormal->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($abnormal->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $abnormal->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $abnormal->old_barcode_product,
                        'old_price_product' => $abnormal->actual_old_price_product,
                        'new_barcode_product' => $abnormal->new_barcode_product,
                        'type' => 'abnormal',
                    ]);
                }
            }

            // Note: Sales abnormal tidak perlu diinsert ke ProductDefect
            // Cukup dijadikan kalkulasi saja dalam value_data_abnormal dan percentage_abnormal
        }

        DB::commit();

        return new ResponseResource(true, "list", [
            "code_document" => $code_document,
            "all data" => $allData,
            "breakdown_all_data" => [
                "inventory" => $inventoryStats->total_count ?? 0,
                "staging" => $stagingStats->total_count ?? 0,
                "product_bundle" => $productBundleStats->total_count ?? 0,
                "product_approve" => $productApproveStats->total_count ?? 0,
                "repair_product" => $repairProductStats->total_count ?? 0,
                "sales" => $salesStats->total_count ?? 0,
                "b2b" => $b2bStats->total_count ?? 0,
                "discrepancy" => count($discrepancy),
            ],
            "lolos" => $countDataLolos,
            "abnormal" => $countDataAbnormal,
            "damaged" => $countDataDamaged,
            "non" => $countDataNon,
            "value_data_non" => $nonPrice,
            'total_price' => $totalPrice,
        ]);
    }
    //ini yg versi 2
    public function findDataDocs2(Request $request, $code_document)
    {
        $userId = auth()->id();
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();
        $document = Document::where('code_document', $code_document)->first();

        $discrepancy = Product_old::where('code_document', $code_document)->select('id', 'old_price_product')->get();

        // Optimasi: Hitung count dan sum sekaligus untuk setiap tabel
        $inventoryStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('
                COUNT(*) as total_count,
                SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price
            ')
            ->first();

        $inventoryLolosStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $inventoryDamagedStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $inventoryAbnormalStats = New_product::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $stagingStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $stagingLolosStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $stagingDamagedStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $stagingAbnormalStats = StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $productBundleStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $productBundleLolosStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $productBundleDamagedStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $productBundleAbnormalStats = Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $productApproveStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $productApproveLolosStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $productApproveDamagedStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $productApproveAbnormalStats = ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $repairProductStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price')
            ->first();

        $repairProductLolosStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price')
            ->first();

        $repairProductDamagedStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price')
            ->first();

        $repairProductAbnormalStats = RepairProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price')
            ->first();

        $salesStats = Sale::where('code_document', $code_document)
            ->selectRaw('COUNT(*) as total_count, SUM(COALESCE(actual_product_old_price_sale, product_old_price_sale)) as total_price')
            ->first();

        // Tambahkan data abnormal dari sales dengan kondisi khusus
        $salesAbnormalStats = null;
        if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
            $salesAbnormalStats = Sale::where('code_document', $code_document)
                ->where('status_product', 'abnormal')
                ->selectRaw('COUNT(*) as abnormal_count, SUM(COALESCE(actual_product_old_price_sale, product_old_price_sale)) as abnormal_price')
                ->first();
        }

        // Hitung total dari semua tabel
        $allData = ($inventoryStats->total_count ?? 0) + ($stagingStats->total_count ?? 0) +
            ($productBundleStats->total_count ?? 0) + ($productApproveStats->total_count ?? 0) +
            ($repairProductStats->total_count ?? 0) +
            ($salesStats->total_count ?? 0);

        // Hitung total price dari semua tabel menggunakan stats yang sudah dioptimasi
        $totalInventoryPrice = $inventoryStats->total_price ?? 0;
        $totalStagingsPrice = $stagingStats->total_price ?? 0;
        $totalProductBundlePrice = $productBundleStats->total_price ?? 0;
        $totalSalesPrice = $salesStats->total_price ?? 0;
        $totalProductApprovePrice = $productApproveStats->total_price ?? 0;
        $totalRepairProductPrice = $repairProductStats->total_price ?? 0;
        $totalDiscrepancyPrice = $discrepancy->sum('old_price_product');

        // Jumlahkan semua total harga
        $totalPrice = $totalInventoryPrice + $totalStagingsPrice + $totalProductBundlePrice +
            $totalSalesPrice + $totalProductApprovePrice +
            $totalRepairProductPrice +
            $totalDiscrepancyPrice;

        // total price in
        $totalPriceIn = $totalInventoryPrice + $totalStagingsPrice + $totalProductBundlePrice +
            $totalSalesPrice + $totalProductApprovePrice +
            $totalRepairProductPrice;

        // Hitung count dan price untuk lolos, damaged, abnormal dari stats yang sudah diperbaiki
        $countDataLolos = ($inventoryLolosStats->lolos_count ?? 0) + ($stagingLolosStats->lolos_count ?? 0) +
            ($productBundleLolosStats->lolos_count ?? 0) + ($productApproveLolosStats->lolos_count ?? 0) +
            ($repairProductLolosStats->lolos_count ?? 0) +
            ($salesStats->total_count ?? 0);

        $lolosPrice = ($inventoryLolosStats->lolos_price ?? 0) + ($stagingLolosStats->lolos_price ?? 0) +
            ($productBundleLolosStats->lolos_price ?? 0) + ($productApproveLolosStats->lolos_price ?? 0) +
            ($repairProductLolosStats->lolos_price ?? 0) +
            ($salesStats->total_price ?? 0);

        $countDataDamaged = ($inventoryDamagedStats->damaged_count ?? 0) + ($stagingDamagedStats->damaged_count ?? 0) +
            ($productBundleDamagedStats->damaged_count ?? 0) + ($productApproveDamagedStats->damaged_count ?? 0) +
            ($repairProductDamagedStats->damaged_count ?? 0);

        $damagedPrice = ($inventoryDamagedStats->damaged_price ?? 0) + ($stagingDamagedStats->damaged_price ?? 0) +
            ($productBundleDamagedStats->damaged_price ?? 0) + ($productApproveDamagedStats->damaged_price ?? 0) +
            ($repairProductDamagedStats->damaged_price ?? 0);
        //tambahkan data abnormal dari sales, ambil dari  sales * where status_product = 'abnormal'
        // kecuali code_document yang  0553/09/2025 dan 0555/09/2025
        $countDataAbnormal = ($inventoryAbnormalStats->abnormal_count ?? 0) + ($stagingAbnormalStats->abnormal_count ?? 0) +
            ($productBundleAbnormalStats->abnormal_count ?? 0) + ($productApproveAbnormalStats->abnormal_count ?? 0) +
            ($repairProductAbnormalStats->abnormal_count ?? 0) +
            ($salesAbnormalStats->abnormal_count ?? 0);

        $abnormalPrice = ($inventoryAbnormalStats->abnormal_price ?? 0) + ($stagingAbnormalStats->abnormal_price ?? 0) +
            ($productBundleAbnormalStats->abnormal_price ?? 0) + ($productApproveAbnormalStats->abnormal_price ?? 0) +
            ($repairProductAbnormalStats->abnormal_price ?? 0) +
            ($salesAbnormalStats->abnormal_price ?? 0);


        // Inisialisasi collections untuk data yang akan diinsert
        $damagedProducts = collect();
        $abnormalProducts = collect();

        $riwayatCheck = RiwayatCheck::where('code_document', $code_document)->first();

        // dd($riwayatCheck);
        if ($riwayatCheck === null) {
            $riwayatCheck = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $code_document,
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => 0,
                'total_data_lolos' => 0,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'done',
                // Persentase (perbaiki typo: precentage -> percentage)
                'percentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => 0,
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,
                'total_price' => $totalPrice,
                'value_data_lolos' => 0,
                'value_data_damaged' => 0,
                'value_data_abnormal' => 0,
                'value_data_discrepancy' => 0,
                'status_file' => 0,
            ]);
        }

        if ($riwayatCheck && ($riwayatCheck->status_file == null || $riwayatCheck->status_file == 0)) {
            // Optimasi: Ambil data damaged dan abnormal hanya ketika akan insert
            $damagedQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
            ];

            $abnormalQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
            ];

            // Tambahkan sales abnormal query jika tidak termasuk dalam exception code_document
            if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                $abnormalQueries[] = Sale::where('code_document', $code_document)
                    ->where('status_product', 'abnormal')
                    ->select('old_barcode_product', 'product_barcode_sale as new_barcode_product', 'actual_product_old_price_sale as actual_old_price_product');
            }

            // Execute queries untuk damaged
            foreach ($damagedQueries as $query) {
                $damagedProducts = $damagedProducts->merge(
                    $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                );
            }

            // Execute queries untuk abnormal  
            foreach ($abnormalQueries as $index => $query) {
                // Cek apakah ini query untuk Sales (yang terakhir dalam array jika ada)
                if ($index === count($abnormalQueries) - 1 && !in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                    // Ini adalah Sales query, sudah ada select yang benar
                    $abnormalProducts = $abnormalProducts->merge($query->get());
                } else {
                    // Ini adalah query untuk tabel lain
                    $abnormalProducts = $abnormalProducts->merge(
                        $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                    );
                }
            }

            $riwayatCheck->update([
                'total_data_in' => $allData,
                'total_data_lolos' => $countDataLolos,
                'total_data_damaged' => $countDataDamaged,
                'total_data_abnormal' => $countDataAbnormal,
                'total_discrepancy' => count($discrepancy),
                'total_price' => $totalPrice,
                'total_price_in' => $totalPriceIn,
                // Persentase
                'precentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalPriceIn / $totalPrice) * 100,
                'percentage_lolos' => ($countDataLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($countDataDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($countDataAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($discrepancy) / $document->total_column_in_document) * 100,
                'value_data_lolos' => $lolosPrice,
                'value_data_damaged' => $damagedPrice,
                'value_data_abnormal' => $abnormalPrice,
                'value_data_discrepancy' => $discrepancy->sum('old_price_product'),
                'status_file' => 1,
            ]);

            // Get existing products untuk optimasi query (hanya dari damaged dan abnormal non-sales)
            $allBarcodes = collect($damagedProducts)->pluck('new_barcode_product')
                ->merge(collect($abnormalProducts)->pluck('new_barcode_product'))
                ->filter()
                ->unique()
                ->values();

            // Note: Sales abnormal barcodes tidak perlu dicek karena tidak diinsert ke ProductDefect

            // Ambil existing records sebagai collection untuk memudahkan lookup
            $existingProducts = ProductDefect::whereIn('new_barcode_product', $allBarcodes)
                ->get()
                ->keyBy('new_barcode_product');

            // Insert atau Update data damaged ke ProductDefect
            foreach ($damagedProducts as $damaged) {
                // Skip jika barcode null
                if (empty($damaged->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($damaged->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($damaged->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $damaged->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $damaged->old_barcode_product,
                        'old_price_product' => $damaged->actual_old_price_product,
                        'new_barcode_product' => $damaged->new_barcode_product,
                        'type' => 'damaged',
                    ]);
                }
            }

            // Insert atau Update data abnormal ke ProductDefect
            foreach ($abnormalProducts as $abnormal) {
                // Skip jika barcode null
                if (empty($abnormal->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($abnormal->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($abnormal->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $abnormal->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $abnormal->old_barcode_product,
                        'old_price_product' => $abnormal->actual_old_price_product,
                        'new_barcode_product' => $abnormal->new_barcode_product,
                        'type' => 'abnormal',
                    ]);
                }
            }

            // Note: Sales abnormal tidak perlu diinsert ke ProductDefect
            // Cukup dijadikan kalkulasi saja dalam value_data_abnormal dan percentage_abnormal
        }

        if ($riwayatCheck && ($riwayatCheck->status_file == 1)) {
            // Optimasi: Ambil data damaged dan abnormal hanya ketika akan insert
            $damagedQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->damaged'),
            ];

            $abnormalQueries = [
                New_product::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                StagingProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                Product_Bundle::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                ProductApprove::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
                RepairProduct::where('code_document', $code_document)->whereNot('new_status_product', 'sale')->whereNotNull('actual_new_quality->abnormal'),
            ];

            // Tambahkan sales abnormal query jika tidak termasuk dalam exception code_document
            if (!in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                $abnormalQueries[] = Sale::where('code_document', $code_document)
                    ->where('status_product', 'abnormal')
                    ->select('old_barcode_product', 'product_barcode_sale as new_barcode_product', 'actual_product_old_price_sale as actual_old_price_product');
            }

            // Execute queries untuk damaged
            foreach ($damagedQueries as $query) {
                $damagedProducts = $damagedProducts->merge(
                    $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                );
            }

            // Execute queries untuk abnormal  
            foreach ($abnormalQueries as $index => $query) {
                // Cek apakah ini query untuk Sales (yang terakhir dalam array jika ada)
                if ($index === count($abnormalQueries) - 1 && !in_array($code_document, ['0553/09/2025', '0555/09/2025'])) {
                    // Ini adalah Sales query, sudah ada select yang benar
                    $abnormalProducts = $abnormalProducts->merge($query->get());
                } else {
                    // Ini adalah query untuk tabel lain
                    $abnormalProducts = $abnormalProducts->merge(
                        $query->select('old_barcode_product', 'new_barcode_product', 'actual_old_price_product')->get()
                    );
                }
            }

            $riwayatCheck->update([
                'total_data_in' => $allData,
                'total_data_lolos' => $countDataLolos,
                'total_data_damaged' => $countDataDamaged,
                'total_data_abnormal' => $countDataAbnormal,
                'total_discrepancy' => count($discrepancy),
                // 'total_price' => $totalPrice,
                'total_price_in' => $totalPriceIn,
                // Persentase
                'precentage_total_data' => ($allData / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalPriceIn / $totalPrice) * 100,
                'percentage_lolos' => ($countDataLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($countDataDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($countDataAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($discrepancy) / $document->total_column_in_document) * 100,
                'value_data_lolos' => $lolosPrice,
                'value_data_damaged' => $damagedPrice,
                'value_data_abnormal' => $abnormalPrice,
                'value_data_discrepancy' => $discrepancy->sum('old_price_product'),
                // 'status_file' => 1,
            ]);

            // Get existing products untuk optimasi query (hanya dari damaged dan abnormal non-sales)
            $allBarcodes = collect($damagedProducts)->pluck('new_barcode_product')
                ->merge(collect($abnormalProducts)->pluck('new_barcode_product'))
                ->filter()
                ->unique()
                ->values();

            // Note: Sales abnormal barcodes tidak perlu dicek karena tidak diinsert ke ProductDefect

            // Ambil existing records sebagai collection untuk memudahkan lookup
            $existingProducts = ProductDefect::whereIn('new_barcode_product', $allBarcodes)
                ->get()
                ->keyBy('new_barcode_product');

            // Insert atau Update data damaged ke ProductDefect
            foreach ($damagedProducts as $damaged) {
                // Skip jika barcode null
                if (empty($damaged->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($damaged->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($damaged->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $damaged->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $damaged->old_barcode_product,
                        'old_price_product' => $damaged->actual_old_price_product,
                        'new_barcode_product' => $damaged->new_barcode_product,
                        'type' => 'damaged',
                    ]);
                }
            }

            // Insert atau Update data abnormal ke ProductDefect
            foreach ($abnormalProducts as $abnormal) {
                // Skip jika barcode null
                if (empty($abnormal->new_barcode_product)) {
                    continue;
                }

                // Cek apakah sudah ada
                if ($existingProducts->has($abnormal->new_barcode_product)) {
                    // Update old_price_product jika sudah ada
                    $existingProduct = $existingProducts->get($abnormal->new_barcode_product);
                    $existingProduct->update([
                        'old_price_product' => $abnormal->actual_old_price_product,
                    ]);
                } else {
                    // Insert baru jika belum ada
                    ProductDefect::create([
                        'code_document' => $code_document ?? null,
                        'riwayat_check_id' => $riwayatCheck->id,
                        'old_barcode_product' => $abnormal->old_barcode_product,
                        'old_price_product' => $abnormal->actual_old_price_product,
                        'new_barcode_product' => $abnormal->new_barcode_product,
                        'type' => 'abnormal',
                    ]);
                }
            }

            // Note: Sales abnormal tidak perlu diinsert ke ProductDefect
            // Cukup dijadikan kalkulasi saja dalam value_data_abnormal dan percentage_abnormal
        }

        DB::commit();

        return new ResponseResource(true, "list", [
            "code_document" => $code_document,
            "all data" => $allData,
            "breakdown_all_data" => [
                "inventory" => $inventoryStats->total_count ?? 0,
                "staging" => $stagingStats->total_count ?? 0,
                "product_bundle" => $productBundleStats->total_count ?? 0,
                "product_approve" => $productApproveStats->total_count ?? 0,
                "repair_product" => $repairProductStats->total_count ?? 0,
                "sales" => $salesStats->total_count ?? 0,
                "discrepancy" => count($discrepancy),
            ],
            "lolos" => $countDataLolos,
            "abnormal" => $countDataAbnormal,
            "damaged" => $countDataDamaged,
            'total_price' => $totalPrice,
        ]);
    }
}
