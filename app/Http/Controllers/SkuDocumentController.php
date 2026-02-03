<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Color_tag;
use App\Models\Generate;
use App\Models\Product_old;
use App\Models\RiwayatCheck;
use App\Models\SkuDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SkuDocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');
        $status = $request->input('f');

        $skuDocuments = SkuDocument::latest();

        if ($query) {
            $skuDocuments->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('code_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%');
            });
        }
        if ($status) {
            $skuDocuments->where('status_document', 'LIKE', '%' . $status . '%');
        }
        $paginated = $skuDocuments->paginate(50);

        return new ResponseResource(true, "List Sku Documents", $paginated);
    }

    public function processExcelFiles(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/sku_imports', $fileName);

        DB::beginTransaction();
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();

            $header = $this->getHeadersFromSheet($sheet);
            $rowCount = $this->processRowsFromSheet($sheet, $header);

            // Create Document Entry
            $code_document = $this->createDocumentEntry($fileName, count($header), $rowCount);

            DB::commit();

            return new ResponseResource(true, "Berhasil upload data ke staging", [
                'code_document' => $code_document,
                'headers' => $header,
                'file_name' => $fileName,
                'total_rows' => $rowCount
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function mapAndMergeHeaders(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');
        $userId = auth()->id();

        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'headerMappings' => 'required|array',
                'code_document' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $headerMappings = $request->input('headerMappings');
            $code_document = $request['code_document'];

            $mergedData = [
                'old_barcode_product' => [],
                'old_name_product' => [],
                'old_quantity_product' => [],
                'old_price_product' => []
            ];

            $stagingData = Generate::all()->map(function ($item) {
                return is_array($item->data) ? $item->data : json_decode($item->data, true);
            });

            foreach ($headerMappings as $dbColumn => $selectedHeaders) {
                if (!array_key_exists($dbColumn, $mergedData)) continue;

                foreach ($selectedHeaders as $excelHeader) {
                    $stagingData->each(function ($row) use ($excelHeader, &$mergedData, $dbColumn) {
                        if (isset($row[$excelHeader])) {
                            $mergedData[$dbColumn][] = $row[$excelHeader];
                        }
                    });
                }
            }

            $dataToInsert = [];

            foreach ($mergedData['old_barcode_product'] as $index => $noResi) {
                $nama = $mergedData['old_name_product'][$index] ?? null;

                $qty = is_numeric($mergedData['old_quantity_product'][$index]) ? (int)$mergedData['old_quantity_product'][$index] : 0;

                if ($nama && strlen($nama) > 2000) {
                    Log::error("Nama produk terlalu panjang, lebih dari 2000 karakter: " . substr($nama, 0, 50) . "...");

                    $nama = substr($nama, 0, 250);
                }

                $harga = isset($mergedData['old_price_product'][$index]) && is_numeric($mergedData['old_price_product'][$index])
                    ? (float)$mergedData['old_price_product'][$index]
                    : 0.0;

                $dataToInsert[] = [
                    'code_document' => $code_document,
                    'old_barcode_product' => $noResi,
                    'old_name_product' => $nama,
                    'old_quantity_product' => $qty,
                    'old_price_product' => $harga,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($dataToInsert, 500) as $chunk) {
                Product_old::insert($chunk);
            }

            Generate::query()->delete();

            $this->createRiwayatCheck($userId, $code_document);

            if (function_exists('logUserAction')) {
                logUserAction($request, $request->user(), "sku/import", "Import SKU generated batch " . $code_document);
            }

            DB::commit();

            return new ResponseResource(true, "Data berhasil dimigrasi. Siap untuk proses scanning.", [
                'total_imported' => count($dataToInsert)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function getHeadersFromSheet($sheet)
    {
        $header = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $val = $cell->getValue();
                if (!is_null($val) && $val !== '') $header[] = $val;
            }
        }
        return $header;
    }

    private function processRowsFromSheet($sheet, $header)
    {
        $rowCount = 0;
        $dataToInsert = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue() ?? '';
            }
            $rowData = array_slice(array_pad($rowData, count($header), ''), 0, count($header));
            $dataToInsert[] = ['data' => json_encode(array_combine($header, $rowData))];

            if (count($dataToInsert) >= 500) {
                Generate::insert($dataToInsert);
                $rowCount += count($dataToInsert);
                $dataToInsert = [];
            }
        }
        if (!empty($dataToInsert)) {
            Generate::insert($dataToInsert);
            $rowCount += count($dataToInsert);
        }
        return $rowCount;
    }

    private function createDocumentEntry($fileName, $cols, $rows)
    {
        $latest = SkuDocument::latest()->first();
        $newId = $latest ? $latest->id + 1 : 1;
        $code = 'SKU-' . str_pad($newId, 4, '0', STR_PAD_LEFT) . '/' . date('m') . '/' . date('Y');

        SkuDocument::create([
            'code_document' => $code,
            'base_document' => $fileName,
            'total_column_document' => $cols,
            'total_column_in_document' => $rows,
            'date_document' => now()->toDateString(),
            'status_document' => 'done'
        ]);
        return $code;
    }

    private function changeBarcodeByDocument($code_document, $init_barcode)
    {
        DB::beginTransaction();
        try {
            $document = SkuDocument::where('code_document', $code_document)->first();

            if (!$document) {
                return 'not_found';
            }

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

        $result = $this->changeBarcodeByDocument($request->code_document, $request->init_barcode);

        if ($result === true) {
            return new ResponseResource(true, "Berhasil mengganti barcode", $request->init_barcode);
        } elseif ($result === 'not_found') {
            return (new ResponseResource(false, "Code document tidak ditemukan", null))
                ->response()
                ->setStatusCode(404);
        } else {
            return (new ResponseResource(false, "Gagal mengganti barcode (Server Error)", null))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function deleteCustomBarcode(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), ['code_document' => 'required']);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $document = SkuDocument::where('code_document', $request->input('code_document'))->first();

            if (!$document) {
                return (new ResponseResource(false, "Code document tidak ditemukan", null))
                    ->response()
                    ->setStatusCode(404);
            }

            $document->update(['custom_barcode' => null]);

            return new ResponseResource(true, "Custom barcode berhasil dihapus", null);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal menghapus barcode", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    private function createRiwayatCheck($userId, $code_document)
    {
        $totalPrice = Product_old::where('code_document', $code_document)->sum('old_price_product');
        $doc = SkuDocument::where('code_document', $code_document)->first();

        RiwayatCheck::create([
            'user_id' => $userId,
            'code_document' => $code_document,
            'base_document' => $doc->base_document ?? 'SKU Import',
            'total_data' => $doc->total_column_in_document ?? 0,
            'status_approve' => 'pending',
            'total_price' => $totalPrice,
            'percentage_in' => 0,
            'status_file' => true,
            'total_data_in' => 0,
            'total_data_lolos' => 0,
            'total_data_damaged' => 0,
            'total_data_abnormal' => 0,
            'total_discrepancy' => $doc->total_column_in_document ?? 0,
            'precentage_total_data' => 0,
            'percentage_lolos' => 0,
            'percentage_damaged' => 0,
            'percentage_abnormal' => 0,
            'percentage_discrepancy' => 100,
            'value_data_lolos' => 0,
            'value_data_damaged' => 0,
            'value_data_abnormal' => 0,
            'value_data_discrepancy' => 0
        ]);
    }
}
