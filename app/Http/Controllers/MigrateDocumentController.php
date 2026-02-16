<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Destination;
use App\Models\Migrate;
use App\Models\MigrateDocument;
use App\Models\New_product;
use App\Models\OlseraProductMapping;
use App\Services\Olsera\OlseraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MigrateDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->has('q')) {
            $migrateDocument = MigrateDocument::when(request()->q, function ($query) {
                $query
                    ->where('code_document_migrate', 'like', '%' . request()->q . '%')
                    ->where('created_at', 'like', '%' . request()->q . '%');
            })
                ->where('status_document_migrate', 'selesai')
                ->latest()
                ->paginate(15);
        } else {
            $migrateDocument = MigrateDocument::where('status_document_migrate', 'selesai')->latest()->paginate(15);
        }
        $resource = new ResponseResource(true, "list dokumen migrate", $migrateDocument);
        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'code_document_migrate' => 'required|unique:migrate_documents',
                'destiny_document_migrate' => 'required',
                'total_product_document_migrate' => 'required|numeric',
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $migrateDocument = MigrateDocument::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $migrateDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }

        return $resource->response();
    }

    /**
     * Display the specified resource.
     */
    public function show(MigrateDocument $migrateDocument)
    {
        $resource = new ResponseResource(true, "Data document migrate", $migrateDocument->load('migrates'));
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MigrateDocument $migrateDocument)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MigrateDocument $migrateDocument)
    {
        try {
            $migrateDocument->delete();
            $resource = new ResponseResource(true, "Data berhasil di hapus!", $migrateDocument);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di hapus!", [$e->getMessage()]);
        }
        return $resource->response();
    }

    public function MigrateDocumentFinish(Request $request)
    {
        DB::beginTransaction();

        $user = auth()->user();
        $userId = $user->id;

        $successCount = 0;
        $processedDocuments = [];

        try {
            $migrateDocuments = MigrateDocument::with('migrates')
                ->where('user_id', $userId)
                ->where('status_document_migrate', 'proses')
                ->get();

            if ($migrateDocuments->isEmpty()) {
                return (new ResponseResource(false, 'Tidak ada dokumen yang perlu diproses.', null))
                    ->response()
                    ->setStatusCode(404);
            }

            foreach ($migrateDocuments as $migrateDocument) {
                $destination = Destination::where('shop_name', $migrateDocument->destiny_document_migrate)->first();

                if ($destination && $destination->is_olsera_integrated) {

                    $olseraService = new OlseraService($destination);
                    $stockInData = [
                        'date' => now()->format('Y-m-d'),
                        'type' => 'I',
                        'note' => 'Migrasi WMS: ' . $migrateDocument->code_document_migrate,
                    ];

                    $resCreate = $olseraService->createStockInOut($stockInData);

                    if (!$resCreate['success']) {
                        throw new \Exception("Gagal membuat Header Stock In di Olsera ({$destination->shop_name}): " . $resCreate['message']);
                    }

                    $olseraPk = $resCreate['data']['id'] ?? $resCreate['data']['pk'] ?? $resCreate['data']['data']['id'] ?? null;

                    if (!$olseraPk) {
                        throw new \Exception("Gagal mendapatkan ID Transaksi (PK) dari respon Olsera.");
                    }

                    $groupedByColor = $migrateDocument->migrates->groupBy('product_color');

                    $olseraCart = [];

                    foreach ($groupedByColor as $wmsIdentifier => $items) {
                        $totalQty = $items->sum('product_total');

                        $mapping = OlseraProductMapping::where('wms_identifier', strtolower($wmsIdentifier))
                            ->where('destination_id', $destination->id)
                            ->first();

                        if (!$mapping) {
                            throw new \Exception("Mapping tidak ditemukan untuk Tag '{$wmsIdentifier}' di Toko '{$destination->shop_name}'. Harap update master mapping.");
                        }

                        $olseraId = $mapping->olsera_id;

                        if (!isset($olseraCart[$olseraId])) {
                            $olseraCart[$olseraId] = [
                                'qty' => 0,
                                'wms_colors' => []
                            ];
                        }

                        $olseraCart[$olseraId]['qty'] += $totalQty;
                        $olseraCart[$olseraId]['wms_colors'][] = $wmsIdentifier;
                    }

                    foreach ($olseraCart as $olseraId => $data) {
                        $addItemData = [
                            'pk' => $olseraPk,
                            'product_ids' => $olseraId,
                            'qty' => $data['qty'],
                            'type' => 'I',
                        ];

                        $resAdd = $olseraService->addItemStockInOut($addItemData);

                        if (!$resAdd['success']) {
                            $combinedColors = implode(', ', $data['wms_colors']);
                            throw new \Exception("Gagal menambah grup item {$combinedColors} (ID Olsera: {$olseraId}): " . $resAdd['message']);
                        }
                    }

                    $updateStatusData = [
                        'pk' => $olseraPk,
                        'status' => 'P'
                    ];

                    $resStatus = $olseraService->updateStatusStockInOut($updateStatusData);

                    if (!$resStatus['success']) {
                        throw new \Exception("Gagal mem-posting (Publish) dokumen Stock In: " . $resStatus['message']);
                    }

                    $migrateDocument->update([
                        'olsera_purchase_id' => $olseraPk,
                        'olsera_response_log' => json_encode($resCreate['data'])
                    ]);

                    Log::info("Sukses Stock In (Published): {$olseraPk} ke {$destination->shop_name}");

                    $pesanLog = "Memproses Migrasi Dokumen {$migrateDocument->code_document_migrate} ke {$destination->shop_name} (Olsera ID: {$olseraPk})";
                    logUserAction($request, $user, 'Migrate Document', $pesanLog);
                } else {
                    $pesanLog = "Memproses Migrasi Dokumen {$migrateDocument->code_document_migrate} ke {$migrateDocument->destiny_document_migrate} (Internal)";
                    logUserAction($request, $user, 'Migrate Document', $pesanLog);
                }

                $relatedMigrates = $migrateDocument->migrates;

                foreach ($relatedMigrates as $m) {
                    $productTotal = $m->product_total;

                    New_product::where('new_tag_product', $m->product_color)
                        ->where('new_status_product', 'display')
                        ->limit($productTotal)
                        ->update(['new_status_product' => 'migrate']);
                }

                Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
                    ->update(['status_migrate' => 'selesai']);

                $migrateDocument->update([
                    'total_product_document_migrate' => $relatedMigrates->sum('product_total'),
                    'status_document_migrate' => 'selesai'
                ]);

                $successCount++;
                $processedDocuments[] = $migrateDocument;
            }

            DB::commit();

            $pesanSummary = "Berhasil menyelesaikan {$successCount} proses migrasi barang keluar.";
            logUserAction($request, $user, 'Migrate Document Finish', $pesanSummary);

            return new ResponseResource(true, "Berhasil memproses {$successCount} dokumen migrasi.", $processedDocuments);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Migrate Finish Error: " . $e->getMessage());

            logUserAction($request, $user, 'Migrate Document Error', "Gagal memproses migrasi: " . $e->getMessage());

            return (new ResponseResource(false, 'Gagal memproses migrasi: ' . $e->getMessage(), []))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function exportMigrateDetail($id)
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();


        $migrateHeaders = [
            'id',
            'code_document_migrate',
            'destiny_document_migrate',
            'total_product_document_migrate',
            'status_document_migrate'
        ];

        $migrateProductHeaders = [
            'code_document_migrate',
            'product_color',
            'product_total',
            'status_migrate'
        ];

        $columnIndex = 1;
        foreach ($migrateHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        $rowIndex = 2;

        $migrate = MigrateDocument::with('migrates')->where('id', $id)->first();
        if ($migrate) {
            $columnIndex = 1;

            foreach ($migrateHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, $rowIndex, $migrate->$header);
                $columnIndex++;
            }
            $rowIndex++;

            $rowIndex++;
            $productColumnIndex = 1;
            foreach ($migrateProductHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $header);
                $productColumnIndex++;
            }
            $rowIndex++;

            if ($migrate->migrates->isNotEmpty()) {
                foreach ($migrate->migrates as $productMigrate) {
                    $productColumnIndex = 1; // Mulai dari kolom pertama
                    foreach ($migrateProductHeaders as $header) {
                        $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $productMigrate->$header);
                        $productColumnIndex++;
                    }
                    $rowIndex++;
                }
            }
            $rowIndex++; // Baris kosong 
        } else {
            $sheet->setCellValueByColumnAndRow(1, 1, 'No data found');
        }

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'exportRepair_' . $migrate->repair_name . '.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        // Membuat direktori exports jika belum ada
        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        // Mengembalikan URL untuk mengunduh file
        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "unduh", $downloadUrl);
    }
}
