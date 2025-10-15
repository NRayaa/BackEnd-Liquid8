<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Palet;
use App\Models\Promo;
use App\Models\Bundle;
use App\Models\Repair;
use App\Models\Document;
use App\Models\ExcelOld;
use App\Models\Generate;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\PaletProduct;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\Product_Bundle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Faker\Factory as Faker;

class GenerateController extends Controller
{
    public function processExcelFiles(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $file->getClientOriginalName());

        DB::beginTransaction();
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $header = $this->getHeadersFromSheet($sheet);
            $rowCount = $this->processRowsFromSheet($sheet, $header);

            $code_document = $this->createDocumentEntry($fileName, count($header), $rowCount);

            DB::commit(); // Commit the transaction

            return new ResponseResource(true, "Berhasil mengimpor data", [
                'code_document' => $code_document,
                'headers' => $header,
                'file_name' => $fileName,
                'fileDetails' => [
                    'total_column_count' => count($header),
                    'total_row_count' => $rowCount,
                ]
            ]);
        } catch (ReaderException $e) {
            DB::rollback();
            return response()->json(['error' => 'Error processing file: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }

    private function getHeadersFromSheet($sheet)
    {
        $header = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!is_null($value) && $value !== '') {
                    $header[] = $value;
                }
            }
        }
        return $header;
    }

    private function processRowsFromSheet($sheet, $header)
    {
        $rowCount = 0;
        $chunkSize = 500;

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

            if (count($dataToInsert) >= $chunkSize) {
                $this->insertChunk($dataToInsert);
                $rowCount += count($dataToInsert);
                $dataToInsert = [];
            }
        }

        // Insert the remaining data
        if (!empty($dataToInsert)) {
            $this->insertChunk($dataToInsert);
            $rowCount += count($dataToInsert);
        }
        return $rowCount;
    }

    private function insertChunk($data)
    {
        DB::transaction(function () use ($data) {
            Generate::insert($data);
        });
    }

    private function createDocumentEntry($fileName, $columnCount, $rowCount)
    {
        $latestDocument = Document::latest()->first();
        $newId = $latestDocument ? $latestDocument->id + 1 : 1;
        $id_document = str_pad($newId, 4, '0', STR_PAD_LEFT);
        $month = date('m');
        $year = date('Y');
        $code_document = $id_document . '/' . $month . '/' . $year;

        Document::create([
            'code_document' => $code_document,
            'base_document' => $fileName,
            'total_column_document' => $columnCount,
            'total_column_in_document' => $rowCount,
            'date_document' => Carbon::now('Asia/Jakarta')->toDateString(),
        ]);

        return $code_document;
    }

    public function mapAndMergeHeaders(Request $request)
    {
        $userId = auth()->id();
        set_time_limit(3600);  
        ini_set('memory_limit', '2048M');

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

            $mergedData = [
                'old_barcode_product' => [],
                'old_name_product' => [],
                'old_quantity_product' => [],
                'old_price_product' => []
            ];

            $ekspedisiData = Generate::all()->map(function ($item) {
                return json_decode($item->data, true);
            });

            foreach ($headerMappings as $templateHeader => $selectedHeaders) {
                foreach ($selectedHeaders as $userSelectedHeader) {
                    $ekspedisiData->each(function ($dataItem) use ($userSelectedHeader, &$mergedData, $templateHeader) {
                        if (isset($dataItem[$userSelectedHeader])) {
                            array_push($mergedData[$templateHeader], $dataItem[$userSelectedHeader]);
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
            
                    // Potong nama menjadi maksimal 250 karakter
                    $nama = substr($nama, 0, 250);
                }
            
                $harga = isset($mergedData['old_price_product'][$index]) && is_numeric($mergedData['old_price_product'][$index])
                    ? (float)$mergedData['old_price_product'][$index]
                    : 0.0;

                $dataToInsert[] = [
                    'code_document' => $request['code_document'],
                    'old_barcode_product' => $noResi,
                    'old_name_product' => $nama,
                    'old_quantity_product' => $qty,
                    'old_price_product' => $harga,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }


            $chunkSize = 500;
            $totalInsertedRows = 0;

            foreach (array_chunk($dataToInsert, $chunkSize) as $chunkIndex => $chunk) {
                $insertResult = Product_old::insert($chunk);
                if ($insertResult) {
                    $insertedRows = count($chunk);
                    $totalInsertedRows += $insertedRows;
                } else {
                    Log::error("Failed to insert chunk {$chunkIndex} into product_olds");
                }
            }
            Generate::query()->delete();

            $product = Product_old::select("old_price_product")->where('code_document', $request['code_document'])->get();
            $totalPrice = $product->sum('old_price_product');
            $totalPrice = ceil($totalPrice);
            $document = Document::where('code_document', $request['code_document'])->first();

            $riwayat_check = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $request['code_document'],
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => 0,
                'total_data_lolos' => 0,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'done',

                // persentase
                'precentage_total_data' => 0,
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
                'status_file' => true,

            ]);


            logUserAction($request, $request->user(), "inbound/data_process/data_input", "Upload inbound batch " . $request['code_document'] . "->" . $userId);

            DB::commit();

            return new ResponseResource(true, "Berhasil menggabungkan data", ['inserted_rows' => $totalInsertedRows, 'riwayat check' => $riwayat_check]);
        } catch (\Illuminate\Database\QueryException $qe) {
            DB::rollBack();
            return response()->json(['error' => 'Database query error: ' . $qe->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Exception in mapAndMergeHeaders: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function deleteAll()
    {
        try {
            Generate::query()->delete();
            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }

    public function deleteAllData()
    {
        try {
            Generate::query()->delete();
            Document::query()->delete();
            Product_old::query()->delete();
            Promo::query()->delete();
            Product_Bundle::query()->delete();
            PaletProduct::query()->delete();
            Bundle::query()->delete();
            Palet::query()->delete();
            RiwayatCheck::query()->delete();
            Repair::query()->delete();
            New_product::query()->delete();

            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }


    public function uploadExcel(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        DB::beginTransaction();

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $header = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', NULL, TRUE, FALSE, TRUE)[1];
            $dataToInsert = [];
            $rowCount = 0;

            foreach ($sheet->getRowIterator(2) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(FALSE);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue() ?? '';
                }

                if (count($header) === count($rowData)) {
                    $dataToInsert[] = ['data' => json_encode(array_combine($header, $rowData))];
                    $rowCount++;
                }
            }

            $chunks = array_chunk($dataToInsert, 500);
            foreach ($chunks as $chunk) {
                ExcelOld::insert($chunk);
            }

            Document::create([
                'code_document' => $this->generateDocumentCode(),
                'base_document' => $fileName,
                'total_column_document' => count($header),
                'total_column_in_document' => $rowCount,
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diunggah dan disimpan',
                'file_name' => $fileName,
                'total_columns' => count($header),
                'total_rows' => $rowCount,
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }


    protected function generateDocumentCode()
    {
        $latestDocument = Document::latest()->first();
        $newId = $latestDocument ? $latestDocument->id + 1 : 1;
        $id_document = str_pad($newId, 4, '0', STR_PAD_LEFT);
        $month = date('m');
        $year = date('Y');
        return $id_document . '/' . $month . '/' . $year;
    }

    public function filterAndCleanExcelOld()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $headerKeys = [
            'Checked Out Buyers',
            'Checked QCD',
            'Checked Bulky Buyer',
            'Checked On Sale',
            'Checked Palet Online',
            'Final Checkout Status'
        ];

        DB::beginTransaction();

        try {
            $dataToRemove = ExcelOld::all()->filter(function ($item) use ($headerKeys) {
                $data = json_decode($item->data, true);

                foreach ($headerKeys as $key) {
                    if (!empty($data[$key])) {
                        return true;
                    }
                }

                return false;
            });

            $dataToRemove->each->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil difilter dan dibersihkan',
                'removed_count' => $dataToRemove->count(),
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function insertCleanedData()
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        $headerMappings = [
            'old_barcode_product' => ['Barcode'],
            'new_barcode_product' => ['Barcode'],
            'new_name_product' => ['Description'],
            'new_category_product' => ['Category'],
            'new_quantity_product' => ['Qty'],
            'new_price_product' => ['Price After Discount'],
            'display_price' => ['Price After Discount'],
            'old_price_product' => ['Unit Price'],
            'new_date_in_product' => ['Date'],
        ];

        $latestDocument = Document::latest()->first();
        if (!$latestDocument) {
            return response()->json(['error' => 'No documents found.'], 404);
        }
        $code_document = $latestDocument->code_document;

        $ekspedisiData = ExcelOld::all()->map(function ($item) {
            return json_decode($item->data, true);
        });


        $mergedData = [
            'old_barcode_product' => [],
            'new_barcode_product' => [],
            'new_name_product' => [],
            'new_category_product' => [],
            'new_quantity_product' => [],
            'new_price_product' => [],
            'old_price_product' => [],
            'new_date_in_product' => [],
            'new_quality' => [],
            'new_discount' => [],
            'display_price' => [],
        ];

        foreach ($ekspedisiData as $dataItem) {
            foreach ($headerMappings as $templateHeader => $selectedHeaders) {
                foreach ($selectedHeaders as $userSelectedHeader) {
                    if (isset($dataItem[$userSelectedHeader])) {
                        $mergedData[$templateHeader][] = $dataItem[$userSelectedHeader];
                    } else {
                        $mergedData[$templateHeader][] = null;
                    }
                }
            }

            $status = $dataItem['Status'] ?? 'unknown';
            $description = $dataItem['Description'] ?? '';

            $qualityData = [
                'lolos' => $status === 'lolos' ? true : null,
                'damaged' => $status === 'damaged' ? $description : null,
                'abnormal' => $status === 'abnormal' ? $description : null,
            ];

            $mergedData['new_quality'][] = json_encode(['lolos' => 'lolos']);
        }

        DB::beginTransaction();
        try {
            foreach ($mergedData['old_barcode_product'] as $index => $barcode) {
                $quantity = isset($mergedData['new_quantity_product'][$index]) && $mergedData['new_quantity_product'][$index] !== '' ? $mergedData['new_quantity_product'][$index] : 0;
                $newProductData = [
                    'code_document' => $code_document,
                    'old_barcode_product' => $barcode,
                    'new_barcode_product' => $mergedData['new_barcode_product'][$index] ?? null,
                    'new_name_product' => $mergedData['new_name_product'][$index] ?? null,
                    'new_category_product' => $mergedData['new_category_product'][$index] ?? null,
                    'new_quantity_product' => $quantity,
                    'new_price_product' => isset($mergedData['new_price_product'][$index]) && $mergedData['new_price_product'][$index] !== '' ? $mergedData['new_price_product'][$index] : 0,
                    'old_price_product' => isset($mergedData['old_price_product'][$index]) && $mergedData['old_price_product'][$index] !== '' ? $mergedData['old_price_product'][$index] : 0,
                    'new_date_in_product' => $mergedData['new_date_in_product'][$index] ?? Carbon::now('Asia/Jakarta')->toDateString(),
                    'new_quality' => $mergedData['new_quality'][$index],
                    'new_discount' => 0,
                    'display_price' => isset($mergedData['display_price'][$index]) && $mergedData['display_price'][$index] !== '' ? $mergedData['display_price'][$index] : 0,
                ];

                New_product::create($newProductData);
            }

            ExcelOld::query()->delete();
            DB::commit();

            Log::info('Merged data prepared for response', ['mergedData' => $mergedData]);

            return new ResponseResource(true, "Data berhasil digabungkan dan disimpan.", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }


    public function createDummyData($count)
    {
        $faker = Faker::create();

        // Non-unique fields to avoid OverflowException
        $barcodes = [];

        for ($i = 0; $i < $count; $i++) {
            $oldBarcode = $faker->ean13;
            while (in_array($oldBarcode, $barcodes)) {
                $oldBarcode = $faker->ean13;
            }
            $barcodes[] = $oldBarcode;

            $newBarcode = $faker->ean13;
            while (in_array($newBarcode, $barcodes)) {
                $newBarcode = $faker->ean13;
            }
            $barcodes[] = $newBarcode;

            $oldPrice = $faker->randomFloat(2, 0, 100000);
            $newPrice = $oldPrice;
            $newTag = '';
            $displayPrice = 0;

            if ($oldPrice >= 50000 && $oldPrice <= 99999) {
                $newTag = 'Biru';
                $displayPrice = 24000.00;
            } elseif ($oldPrice >= 20000 && $oldPrice <= 49999) {
                $newTag = 'Merah';
                $displayPrice = 12000.00;
            } elseif ($oldPrice >= 0 && $oldPrice <= 19999) {
                $newTag = 'Brown';
                $displayPrice = 0.00;
            }

            New_product::create([
                'code_document' => $faker->unique()->word,
                'old_barcode_product' => $oldBarcode,
                'new_barcode_product' => $newBarcode,
                'new_name_product' => $faker->word,
                'new_quantity_product' => $faker->numberBetween(1, 100),
                'new_price_product' => $newPrice,
                'old_price_product' => $oldPrice,
                'new_date_in_product' => $faker->date,
                'new_status_product' => 'display',
                'new_quality' => json_encode(['lolos' => 'lolos']),
                'new_category_product' => null,
                'new_tag_product' => $newTag,
                'new_discount' => $faker->randomFloat(2, 0, 100),
                'display_price' => $displayPrice,
            ]);
        }

        return response()->json(['message' => "$count dummy data created successfully."]);
    }

    public function exportTemplate(Request $request)
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        // Membuat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Menentukan headers berdasarkan nama kolom di tabel new_products
        $headers = [
            'Barcode',
            'Description',
            'Category',
            'Qty',
            'Price After Discount',
            'Unit Price'
        ];

        // Menuliskan headers ke sheet
        $columnIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }


        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'templates_display.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        // Membuat direktori exports jika belum ada
        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        // Mengembalikan URL untuk mengunduh file
        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "file diunduh", $downloadUrl);
    }
}
