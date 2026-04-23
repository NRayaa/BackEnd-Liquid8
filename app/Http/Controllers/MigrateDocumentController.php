<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ColorRack;
use App\Models\ColorRackProduct;
use App\Models\Destination;
use App\Models\Migrate;
use App\Models\MigrateDocument;
use App\Models\New_product;
use App\Models\OlseraProductMapping;
use App\Services\Olsera\OlseraService;
use App\Services\Pos\PosService;
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
        $querySearch = request()->q;

        $migrateDocument = MigrateDocument::where('status_document_migrate', 'selesai')
            ->when($querySearch, function ($query) use ($querySearch) {
                $query->where(function ($subQuery) use ($querySearch) {
                    $subQuery->where('code_document_migrate', 'like', '%' . $querySearch . '%')
                        ->orWhere('created_at', 'like', '%' . $querySearch . '%');
                });
            })
            ->latest()
            ->paginate(15);

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
        $migrateDocument->load('migrates.colorRack');

        $resource = new ResponseResource(true, "Data document migrate", $migrateDocument);

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

    // public function MigrateDocumentFinish(Request $request)
    // {
    //     DB::beginTransaction();

    //     $user = auth()->user();
    //     $userId = $user->id;

    //     $successCount = 0;
    //     $processedDocuments = [];

    //     try {
    //         $migrateDocuments = MigrateDocument::with('migrates')
    //             ->where('user_id', $userId)
    //             ->where('status_document_migrate', 'proses')
    //             ->get();

    //         if ($migrateDocuments->isEmpty()) {
    //             return (new ResponseResource(false, 'Tidak ada dokumen yang perlu diproses.', null))
    //                 ->response()
    //                 ->setStatusCode(404);
    //         }

    //         foreach ($migrateDocuments as $migrateDocument) {
    //             $destination = Destination::where('shop_name', $migrateDocument->destiny_document_migrate)->first();

    //             if ($destination && $destination->is_olsera_integrated) {

    //                 $olseraService = new OlseraService($destination);
    //                 $stockInData = [
    //                     'date' => now()->format('Y-m-d'),
    //                     'type' => 'I',
    //                     'note' => 'Migrasi WMS: ' . $migrateDocument->code_document_migrate,
    //                 ];

    //                 $resCreate = $olseraService->createStockInOut($stockInData);

    //                 if (!$resCreate['success']) {
    //                     throw new \Exception("Gagal membuat Header Stock In di Olsera ({$destination->shop_name}): " . $resCreate['message']);
    //                 }

    //                 $olseraPk = $resCreate['data']['id'] ?? $resCreate['data']['pk'] ?? $resCreate['data']['data']['id'] ?? null;

    //                 if (!$olseraPk) {
    //                     throw new \Exception("Gagal mendapatkan ID Transaksi (PK) dari respon Olsera.");
    //                 }

    //                 $groupedByColor = $migrateDocument->migrates->groupBy('product_color');

    //                 $olseraCart = [];

    //                 foreach ($groupedByColor as $wmsIdentifier => $items) {
    //                     $totalQty = $items->sum('product_total');

    //                     $mapping = OlseraProductMapping::where('wms_identifier', strtolower($wmsIdentifier))
    //                         ->where('destination_id', $destination->id)
    //                         ->first();

    //                     if (!$mapping) {
    //                         throw new \Exception("Mapping tidak ditemukan untuk Tag '{$wmsIdentifier}' di Toko '{$destination->shop_name}'. Harap update master mapping.");
    //                     }

    //                     $olseraId = $mapping->olsera_id;

    //                     if (!isset($olseraCart[$olseraId])) {
    //                         $olseraCart[$olseraId] = [
    //                             'qty' => 0,
    //                             'wms_colors' => []
    //                         ];
    //                     }

    //                     $olseraCart[$olseraId]['qty'] += $totalQty;
    //                     $olseraCart[$olseraId]['wms_colors'][] = $wmsIdentifier;
    //                 }

    //                 foreach ($olseraCart as $olseraId => $data) {
    //                     $addItemData = [
    //                         'pk' => $olseraPk,
    //                         'product_ids' => $olseraId,
    //                         'qty' => $data['qty'],
    //                         'type' => 'I',
    //                     ];

    //                     $resAdd = $olseraService->addItemStockInOut($addItemData);

    //                     if (!$resAdd['success']) {
    //                         $combinedColors = implode(', ', $data['wms_colors']);
    //                         throw new \Exception("Gagal menambah grup item {$combinedColors} (ID Olsera: {$olseraId}): " . $resAdd['message']);
    //                     }
    //                 }

    //                 $updateStatusData = [
    //                     'pk' => $olseraPk,
    //                     'status' => 'P'
    //                 ];

    //                 $resStatus = $olseraService->updateStatusStockInOut($updateStatusData);

    //                 if (!$resStatus['success']) {
    //                     throw new \Exception("Gagal mem-posting (Publish) dokumen Stock In: " . $resStatus['message']);
    //                 }

    //                 $migrateDocument->update([
    //                     'olsera_purchase_id' => $olseraPk,
    //                     'olsera_response_log' => json_encode($resCreate['data'])
    //                 ]);

    //                 Log::info("Sukses Stock In (Published): {$olseraPk} ke {$destination->shop_name}");

    //                 $pesanLog = "Memproses Migrasi Dokumen {$migrateDocument->code_document_migrate} ke {$destination->shop_name} (Olsera ID: {$olseraPk})";
    //                 logUserAction($request, $user, 'Migrate Document', $pesanLog);
    //             } else {
    //                 $pesanLog = "Memproses Migrasi Dokumen {$migrateDocument->code_document_migrate} ke {$migrateDocument->destiny_document_migrate} (Internal)";
    //                 logUserAction($request, $user, 'Migrate Document', $pesanLog);
    //             }

    //             $relatedMigrates = $migrateDocument->migrates;

    //             foreach ($relatedMigrates as $m) {
    //                 $productTotal = $m->product_total;

    //                 New_product::where('new_tag_product', $m->product_color)
    //                     ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
    //                     ->whereNull('is_so')
    //                     // ->where('is_so', 'done')
    //                     ->whereNull('new_category_product')
    //                     ->whereJsonContains('new_quality->lolos', 'lolos')
    //                     ->where(function ($q) {
    //                         $q->whereNull('type')
    //                             ->orWhereIn('type', ['type1', 'type2']);
    //                     })
    //                     ->orderBy('created_at', 'asc')
    //                     ->limit($productTotal)
    //                     ->update(['new_status_product' => 'migrate']);
    //             }

    //             Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
    //                 ->update(['status_migrate' => 'selesai']);

    //             $migrateDocument->update([
    //                 'total_product_document_migrate' => $relatedMigrates->sum('product_total'),
    //                 'status_document_migrate' => 'selesai'
    //             ]);

    //             $successCount++;
    //             $processedDocuments[] = $migrateDocument;
    //         }

    //         DB::commit();

    //         $pesanSummary = "Berhasil menyelesaikan {$successCount} proses migrasi barang keluar.";
    //         logUserAction($request, $user, 'Migrate Document Finish', $pesanSummary);

    //         return new ResponseResource(true, "Berhasil memproses {$successCount} dokumen migrasi.", $processedDocuments);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error("Migrate Finish Error: " . $e->getMessage());

    //         logUserAction($request, $user, 'Migrate Document Error', "Gagal memproses migrasi: " . $e->getMessage());

    //         return (new ResponseResource(false, 'Gagal memproses migrasi: ' . $e->getMessage(), []))
    //             ->response()
    //             ->setStatusCode(500);
    //     }
    // }

    public function MigrateDocumentFinish(Request $request)
    {
        DB::beginTransaction();

        $user = auth()->user();
        $userId = $user->id;
        $successCount = 0;
        $processedDocuments = [];

        try {
            $migrateDocuments = MigrateDocument::with('migrates')->where('user_id', $userId)->where('status_document_migrate', 'proses')->get();

            if ($migrateDocuments->isEmpty()) {
                return (new ResponseResource(false, 'Tidak ada dokumen yang perlu diproses.', null))->response()->setStatusCode(404);
            }

            $posService = new PosService();

            foreach ($migrateDocuments as $migrateDocument) {
                $destination = Destination::where('shop_name', $migrateDocument->destiny_document_migrate)->first();

                if (!$destination || empty($destination->pos_token)) {
                    throw new \Exception("Toko tujuan '{$migrateDocument->destiny_document_migrate}' tidak ditemukan atau belum memiliki POS Token. Silakan jalankan Seeder POS.");
                }

                $storeToken = $destination->pos_token;
                $allProductsToSend = collect();

                foreach ($migrateDocument->migrates as $migrateItem) {
                    if ($migrateItem->color_rack_id) {
                        $rackProducts = ColorRackProduct::with(['newProduct', 'bundle'])->where('color_rack_id', $migrateItem->color_rack_id)->get();

                        foreach ($rackProducts as $item) {
                            if ($item->bundle_id && $item->bundle) {
                                $allProductsToSend->push([
                                    "code_document"    => $item->bundle->code_document_bundle ?? "-",
                                    "old_barcode"      => null,
                                    "old_price"        => (float) $item->bundle->total_price_bundle,
                                    "actual_price"     => (float) $item->bundle->total_price_bundle,
                                    "barcode"          => $item->bundle->barcode_bundle,
                                    "name"             => "[BUNDLE] " . $item->bundle->name_bundle,
                                    "price"            => (float) $item->bundle->total_price_custom_bundle,
                                    "quantity"         => 1,
                                    "status"           => "active",
                                    "tag_color"        => $item->bundle->name_color ?? "bundle",
                                    "is_so"            => $item->bundle->is_so,
                                    "is_extra_product" => false,
                                    "user_so"          => $item->bundle->user_so
                                ]);

                                $item->bundle->update(['product_status' => 'migrate']);
                            } elseif ($item->new_product_id && $item->newProduct) {
                                $product = $item->newProduct;
                                $allProductsToSend->push([
                                    "code_document"    => $product->code_document ?? "-",
                                    "old_barcode"      => $product->old_barcode_product,
                                    "old_price"        => (float) ($product->old_price_product),
                                    "actual_price"     => (float) ($product->actual_old_price_product),
                                    "barcode"          => $product->new_barcode_product,
                                    "name"             => $product->new_name_product,
                                    "price"            => (float) ($product->new_price_product),
                                    "quantity"         => $product->new_quantity_product ?? 1,
                                    "status"           => "active",
                                    "tag_color"        => $product->new_tag_product ?? "color",
                                    "is_so"            => $product->is_so,
                                    "is_extra_product" => (bool) $product->is_extra,
                                    "user_so"          => $product->user_so
                                ]);

                                $product->update(['new_status_product' => 'migrate']);
                            }
                        }

                        ColorRack::where('id', $migrateItem->color_rack_id)->update(['status' => 'migrate']);
                    }
                }

                $totalProducts = $allProductsToSend->count();
                if ($totalProducts == 0) continue;

                $chunks = $allProductsToSend->chunk(100);

                foreach ($chunks as $index => $batch) {
                    try {
                        $posService->sendBatchProducts(
                            $migrateDocument->code_document_migrate,
                            $storeToken,
                            array_values($batch->toArray())
                        );

                        Log::info("Berhasil mengirim Batch " . ($index + 1) . "/{$chunks->count()} ke POS untuk Dokumen {$migrateDocument->code_document_migrate}");
                    } catch (\Exception $e) {
                        throw new \Exception("Gagal saat mengirim batch ke-" . ($index + 1) . " ke POS: " . $e->getMessage());
                    }
                }

                Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
                    ->update(['status_migrate' => 'selesai']);

                $migrateDocument->update([
                    'total_product_document_migrate' => $totalProducts,
                    'status_document_migrate'        => 'selesai'
                ]);

                $pesanLog = "Memproses Migrasi Dokumen {$migrateDocument->code_document_migrate} ke {$migrateDocument->destiny_document_migrate} (via POS). Total: {$totalProducts} Item.";
                logUserAction($request, $user, 'Migrate Document Finish', $pesanLog);

                $successCount++;
                $processedDocuments[] = $migrateDocument;
            }

            DB::commit();

            $pesanSummary = "Berhasil menyelesaikan {$successCount} proses migrasi barang keluar ke POS.";
            logUserAction($request, $user, 'Migrate Document Finish', $pesanSummary);

            return new ResponseResource(true, "Berhasil memproses {$successCount} dokumen migrasi ke POS.", $processedDocuments);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Migrate Finish Error: " . $e->getMessage());

            logUserAction($request, $user, 'Migrate Document Error', "Gagal memproses migrasi");

            return (new ResponseResource(false, 'Gagal memproses migrasi: ' . $e->getMessage(), []))
                ->response()->setStatusCode(500);
        }
    }

    public function exportMigrateDetail($id)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $migrate = MigrateDocument::with([
            'migrates.colorRack.colorRackProducts.newProduct',
            'migrates.colorRack.colorRackProducts.bundle'
        ])->where('id', $id)->first();

        $fileName = 'Migrate_Not_Found_' . time() . '.xlsx';

        if (!$migrate) {
            $sheet->setCellValueByColumnAndRow(1, 1, 'No data found');
        } else {
            $fileName = 'Migrate_' . $migrate->code_document_migrate . '.xlsx';

            $destinationName = $migrate->destiny_document_migrate;
            $destObj = \App\Models\Destination::find($migrate->destiny_document_migrate);
            if ($destObj) {
                $destinationName = $destObj->shop_name;
            }

            $migrateHeaders = [
                'ID Dokumen',
                'Kode Dokumen',
                'Destinasi (Toko)',
                'Total Produk',
                'Status Dokumen'
            ];

            $migrateData = [
                $migrate->id,
                $migrate->code_document_migrate,
                $destinationName,
                $migrate->total_product_document_migrate,
                strtoupper($migrate->status_document_migrate)
            ];

            // Tulis Header Umum
            $columnIndex = 1;
            foreach ($migrateHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
                $sheet->setCellValueByColumnAndRow($columnIndex, 2, $migrateData[$columnIndex - 1]);
                $columnIndex++;
            }

            $rowIndex = 4;
            $productHeaders = [
                'Nama Rak Color',
                'Barcode Rak',
                'Tipe Item',
                'Nama Produk / Bundle',
                'Barcode Item',
                'Harga Awal',
                'Harga POS (Actual)',
                'Status Fisik WMS'
            ];

            $productColumnIndex = 1;
            foreach ($productHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $header);
                $sheet->getStyleByColumnAndRow($productColumnIndex, $rowIndex)->getFont()->setBold(true);
                $productColumnIndex++;
            }
            $rowIndex++;

            if ($migrate->migrates->isNotEmpty()) {
                foreach ($migrate->migrates as $migrateItem) {
                    $rack = $migrateItem->colorRack;
                    $rackName = $rack ? $rack->name : 'Rak Dihapus';
                    $rackBarcode = $rack ? $rack->barcode : '-';

                    if ($rack && $rack->colorRackProducts->isNotEmpty()) {
                        foreach ($rack->colorRackProducts as $cp) {
                            $type = '-';
                            $itemName = '-';
                            $itemBarcode = '-';
                            $oldPrice = 0;
                            $newPrice = 0;
                            $status = '-';

                            // Jika item adalah BUNDLE
                            if ($cp->bundle_id && $cp->bundle) {
                                $type        = 'Bundle';
                                $itemName    = '[BUNDLE] ' . $cp->bundle->name_bundle;
                                $itemBarcode = $cp->bundle->barcode_bundle;
                                $oldPrice    = $cp->bundle->total_price_bundle;
                                $newPrice    = $cp->bundle->total_price_custom_bundle;
                                $status      = $cp->bundle->product_status;
                            }
                            // Jika item adalah PRODUK BIASA
                            elseif ($cp->new_product_id && $cp->newProduct) {
                                $type        = 'Produk';
                                $itemName    = $cp->newProduct->new_name_product;
                                $itemBarcode = $cp->newProduct->new_barcode_product;
                                $oldPrice    = $cp->newProduct->old_price_eq ?? $cp->newProduct->old_price_product ?? 0;
                                $newPrice    = $cp->newProduct->new_price_eq ?? $cp->newProduct->new_price_product ?? 0;
                                $status      = $cp->newProduct->new_status_product;
                            } else {
                                continue;
                            }

                            $sheet->setCellValueByColumnAndRow(1, $rowIndex, $rackName);
                            $sheet->setCellValueByColumnAndRow(2, $rowIndex, $rackBarcode);
                            $sheet->setCellValueByColumnAndRow(3, $rowIndex, $type);
                            $sheet->setCellValueByColumnAndRow(4, $rowIndex, $itemName);
                            $sheet->setCellValueByColumnAndRow(5, $rowIndex, $itemBarcode);
                            $sheet->setCellValueByColumnAndRow(6, $rowIndex, $oldPrice);
                            $sheet->setCellValueByColumnAndRow(7, $rowIndex, $newPrice);
                            $sheet->setCellValueByColumnAndRow(8, $rowIndex, strtoupper($status));

                            $rowIndex++;
                        }
                    } else {
                        // Jika ternyata rak kosong/dihapus isinya, tetap tampilkan baris raknya sebagai info
                        $sheet->setCellValueByColumnAndRow(1, $rowIndex, $rackName);
                        $sheet->setCellValueByColumnAndRow(2, $rowIndex, $rackBarcode);
                        $sheet->setCellValueByColumnAndRow(3, $rowIndex, 'KOSONG');
                        $rowIndex++;
                    }
                }
            }
        }

        $writer = new Xlsx($spreadsheet);
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName) . '?t=' . time();

        return new ResponseResource(true, "Berhasil mengunduh dokumen detail migrasi", $downloadUrl);
    }
}
