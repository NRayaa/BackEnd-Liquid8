<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Document;
use App\Models\FilterStaging;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\Product_Bundle;
use App\Models\Product_old;
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

            logUserAction($request, $request->user(), "inbound/check_product/list_data", "delete document->" . $user);

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

        $document = Document::where('code_document', $code_document)->first();

        $discrepancy = Product_old::where('code_document', $code_document)->select('id', 'old_price_product')->get();

        $inventory = New_product::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $stagings = StagingProduct::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $stagingApproves = StagingApprove::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $filterStagings = FilterStaging::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $productBundle = Product_Bundle::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $sales = Sale::where('code_document', $code_document)->select('code_document', 'product_old_price_sale')->get();

        $productApprove = ProductApprove::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $repairFilter = RepairFilter::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $repairProduct = RepairProduct::where('code_document', $code_document)
            ->select('new_quality', 'code_document', 'old_price_product')->get();

        $allData = count($inventory) + count($stagings) + count($filterStagings) + count($productBundle)
            + count($productApprove) + count($repairFilter) + count($repairProduct) + count($sales) + count($stagingApproves);

        $totalInventoryPrice = $inventory->sum('old_price_product');
        $totalStagingsPrice = $stagings->sum('old_price_product') + $filterStagings->sum('old_price_product') + $stagingApproves->sum('old_price_product');
        $totalProductBundlePrice = $productBundle->sum('old_price_product');
        $totalSalesPrice = $sales->sum('product_old_price_sale');
        $totalProductApprovePrice = $productApprove->sum('old_price_product');
        $totalRepairFilterPrice = $repairFilter->sum('old_price_product');
        $totalRepairProductPrice = $repairProduct->sum('old_price_product');

        // Jumlahkan semua total harga
        $totalPrice = $totalInventoryPrice + $totalStagingsPrice + $totalProductBundlePrice +
            $totalSalesPrice + $totalProductApprovePrice +
            $totalRepairFilterPrice + $totalRepairProductPrice;

        //count lolos
        $countDataLolos = New_product::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            StagingProduct::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            FilterStaging::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            StagingApprove::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            ProductApprove::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            RepairFilter::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            RepairProduct::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->count()
            +
            Sale::where('code_document', $code_document)->count();

        // Menghitung 'damaged' secara langsung menggunakan query
        $countDataDamaged = New_product::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            StagingProduct::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            FilterStaging::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            StagingApprove::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            ProductApprove::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            RepairFilter::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count()
            +
            RepairProduct::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->count();

        // Menghitung 'abnormal' secara langsung menggunakan query
        $countDataAbnormal = New_product::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            StagingProduct::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            FilterStaging::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            StagingApprove::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            ProductApprove::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            RepairFilter::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count()
            +
            RepairProduct::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->count();

        $riwayatCheck = RiwayatCheck::where('code_document', $code_document)->first();

        if ($riwayatCheck === null) {
            $riwayat_check = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $code_document, // Menggunakan $code_document langsung
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => 0,
                'total_data_lolos' => 0,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'done',

                // Persentase
                'precentage_total_data' => 0,
                'percentage_in' => 0,
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,

                'total_price' => $totalPrice, // Pastikan $totalPrice terinisialisasi
            ]);
        }

        $riwayatCheck->update([
            'total_data_in' => $allData,
            'total_data_lolos' => $countDataLolos,
            'total_data_damaged' => $countDataDamaged,
            'total_data_abnormal' => $countDataAbnormal,
            'total_discrepancy' => count($discrepancy),
            'total_price' => $totalPrice,

            // persentase
            'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
            'percentage_in' => ($allData / $document->total_column_in_document) * 100,
            'percentage_lolos' => ($countDataLolos / $document->total_column_in_document) * 100,
            'percentage_damaged' => ($countDataDamaged / $document->total_column_in_document) * 100,
            'percentage_abnormal' => ($countDataAbnormal / $document->total_column_in_document) * 100,
            'percentage_discrepancy' => (count($discrepancy) / $document->total_column_in_document) * 100,
        ]);

        return new ResponseResource(true, "list", [
            "code_document" => $code_document,
            "all data" => $allData,
            "lolos" => $countDataLolos,
            "abnormal" => $countDataAbnormal,
            "damaged" => $countDataDamaged,
            'total_price' => $totalPrice,
        ]);
    }
}
