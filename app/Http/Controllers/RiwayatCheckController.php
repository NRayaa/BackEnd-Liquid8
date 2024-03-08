<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Mail\TestEmail;
use App\Models\Document;
use App\Models\New_product;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Mail\AdminNotification;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\Notification;
use App\Models\ProductApprove;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RiwayatCheckController extends Controller
{

    public function index()
    {
        $riwayats = RiwayatCheck::latest()->paginate(50);
        return new ResponseResource(true, "list riwayat", $riwayats);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $user = User::find(auth()->id());

        if (!$user) {
            $resource = new ResponseResource(false, "User tidak dikenali", null);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'code_document' => 'required|unique:riwayat_checks,code_document',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = Document::where('code_document', $request['code_document'])->firstOrFail();

        if ($document->total_column_in_document == 0) {
            return response()->json(['error' => 'Total data di document tidak boleh 0'], 422);
        }

        DB::beginTransaction();

        try {

            $newProducts = ProductApprove::where('code_document', $request['code_document'])->get();

            $totalData = $newProducts->count();
            $totalLolos = $totalDamaged = $totalAbnormal = 0;


            foreach ($newProducts as $product) {
                $newQualityData = json_decode($product->new_quality, true);

                if (is_array($newQualityData)) {
                    $totalLolos += !empty($newQualityData['lolos']) ? 1 : 0;
                    $totalDamaged += !empty($newQualityData['damaged']) ? 1 : 0;
                    $totalAbnormal += !empty($newQualityData['abnormal']) ? 1 : 0;
                }
            }

            $riwayat_check = RiwayatCheck::create([
                'user_id' => $user->id,
                'code_document' => $request['code_document'],
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => $totalData,
                'total_data_lolos' => $totalLolos,
                'total_data_damaged' => $totalDamaged,
                'total_data_abnormal' => $totalAbnormal,
                'total_discrepancy' => $document->total_column_in_document - $totalData,
                'status_approve' => 'pending',

                // persentase
                'precentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalData / $document->total_column_in_document) * 100,
                'percentage_lolos' => ($totalLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($totalDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($totalAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (($document->total_column_in_document - $totalData) / $document->total_column_in_document) * 100,
            ]);


            $code_document = Document::where('code_document', $request['code_document'])->first();
            $code_document->update(['status_document' => 'done']);

            //keterangan transaksi
            $keterangan = Notification::create([
                'user_id' => $user->id,
                'notification_name' => 'Butuh approvement untuk List Product',
                'role' => 'Spv',
                'read_at' => Carbon::now('Asia/Jakarta'),
                'riwayat_check_id' => $riwayat_check->id,
                'repair_id' => null 
            ]);

            // $adminUser = User::where('email', 'isagagah3@gmail.com')->first();

            // if ($adminUser) {
            //     Mail::to($adminUser->email)->send(new AdminNotification($adminUser, $keterangan->id));
            // } else {
            //     $resource = new ResponseResource(false, "email atau transaksi tidak ditemukan", null);
            //     return $resource->response()->setStatusCode(403);
            // }

            DB::commit();

            return new ResponseResource(true, "Data berhasil ditambah", [
                $riwayat_check, $keterangan
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $resource = new ResponseResource(false, "Data gagal ditambahkan, terjadi kesalahan pada server : " . $e->getMessage(), null);
            $resource->response()->setStatusCode(500);
        }
    }


    public function show(RiwayatCheck $history)
    {
        $getProductDamaged = New_product::where('code_document', $history->code_document)
            ->where('new_quality->damaged', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.damaged")) AS damaged_value'),
                'new_quantity_product',
                'old_price_product',
            )
            ->get();

        $totalOldPriceDamaged = $getProductDamaged->sum(function ($product) {
            return $product->old_price_product;
        });

        $getProductLolos = New_product::where('code_document', $history->code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.lolos")) AS lolos_value'),
                'new_quantity_product',
                'old_price_product',
                'new_category_product',
                'new_price_product'
            )
            ->get();
        $totalOldPriceLolos = $getProductLolos->sum(function ($product) {
            return $product->old_price_product;
        });

        $getProductAbnormal = New_product::where('code_document', $history->code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.abnormal")) AS abnormal_value'),
                'new_quantity_product',
                'old_price_product',
            )
            ->get();
        $totalOldPriceAbnormal = $getProductAbnormal->sum(function ($product) {
            return $product->old_price_product;
        });

        $response = new ResponseResource(true, "Riwayat Check", [
            'id' => $history->id,
            'user_id' => $history->user_id,
            'code_document' => $history->code_document,
            'base_document' => $history->base_document,
            'total_data' => $history->total_data,
            'total_data_in' => $history->total_data_in,
            'total_data_lolos' => $history->total_data_lolos,
            'total_data_damaged' => $history->total_data_damaged,
            'total_data_abnormal' => $history->total_data_abnormal,
            'total_discrepancy' => $history->total_discrepancy,
            'status_approve' => $history->status_approve,
            'precentage_total_data' => $history->precentage_total_data,
            'percentage_in' => $history->percentage_in,
            'percentage_lolos' => $history->percentage_lolos,
            'percentage_damaged' => $history->percentage_damaged,
            'percentage_abnormal' => $history->percentage_abnormal,
            'percentage_discrepancy' => $history->percentage_discrepancy,
            'created_at' => $history->created_at,
            'updated_at' => $history->updated_at,
            'damaged' => [
                'products' => $getProductDamaged,
                'total_old_price' => $totalOldPriceDamaged,
            ],
            'lolos' => [
                'products' => $getProductLolos,
                'total_old_price' => $totalOldPriceLolos,
            ],
            'abnormal' => [
                'products' => $getProductAbnormal,
                'total_old_price' => $totalOldPriceAbnormal,
            ],
        ]);

        return $response->response();
    }



    public function getByDocument(Request $request)
    {
        $codeDocument = RiwayatCheck::where('code_document', $request['code_document']);
        return new ResponseResource(true, "Riwayat Check", $codeDocument);
    }


    public function edit(RiwayatCheck $riwayatCheck)
    {
        //
    }


    public function update(Request $request, RiwayatCheck $riwayatCheck)
    {
        //
    }


    public function destroy(RiwayatCheck $history)
    {
        DB::beginTransaction();
        try {
            Notification::where('riwayat_check_id', $history->id)->delete();
            $history->delete();
            DB::commit();
            return new ResponseResource(true, 'data berhasil di hapus', $history);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, 'data gagal di hapus', $e->getMessage());
        }
    }

    public function exportToExcel(Request $request)
    {
        $code_document = $request->input('code_document');

        $getProductDamaged = New_product::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.damaged")) AS damaged_value'),
                'new_quantity_product',
                'old_price_product',
            )
            ->get();

        $totalOldPriceDamaged = $getProductDamaged->sum(function ($product) {
            return $product->old_price_product;
        });

        $getProductLolos = New_product::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.lolos")) AS lolos_value'),
                'new_quantity_product',
                'old_price_product',
                'new_category_product',
                'new_price_product'
            )
            ->get();
        $totalOldPriceLolos = $getProductLolos->sum(function ($product) {
            return $product->old_price_product;
        });

        $getProductAbnormal = New_product::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select(
                'code_document',
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(new_quality, "$.abnormal")) AS abnormal_value'),
                'new_quantity_product',
                'old_price_product',
            )
            ->get();

        $totalOldPriceAbnormal = $getProductAbnormal->sum(function ($product) {
            return $product->old_price_product;
        });

        // $code_document = '0001/02/2024';
        $checkHistory = RiwayatCheck::where('code_document', $code_document)->get();

        if ($checkHistory->isEmpty()) {
            return response()->json(['status' => false, 'message' => "Data kosong, tidak bisa di export"], 422);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header dan data disimpan secara vertikal
        $headers = [
            'ID', 'User ID', 'Code Document', 'Base Document', 'Total Data', 'Total Data In', 'Total Data Lolos', 'Total Data Damaged', 'Total Data Abnormal', 'Total Discrepancy', 'Status Approve', 'Percentage Total Data', 'Percentage In', 'Percentage Lolos', 'Percentage Damaged', 'Percentage Abnormal', 'Percentage Discrepancy'
        ];

        $currentRow = 1;
        foreach ($checkHistory as $riwayatCheck) {
            foreach ($headers as $index => $header) {
                $columnName = strtolower(str_replace(' ', '_', $header));
                $cellValue = $riwayatCheck->$columnName;
                // Set header
                $sheet->setCellValueByColumnAndRow(1, $currentRow, $header);
                // Set value
                $sheet->setCellValueByColumnAndRow(2, $currentRow, $cellValue);
                $currentRow++; // Pindah ke baris berikutnya
            }
            // Menambahkan baris kosong setelah setiap data checkHistory
            $currentRow++;
        }

        // ========================================= Buat lembar kerja baru untuk produk damaged =====================================
        $secondSheet = $spreadsheet->createSheet();
        $secondSheet->setTitle('Damaged');
        $currentRow2 = 1;

        // Set data untuk lembar kerja produk rusak
        $this->setSheetDataProductDamaged($secondSheet, $getProductDamaged, $currentRow2, $totalOldPriceDamaged);


        // ========================================= Buat lembar kerja baru untuk produk lolos =====================================
        $fourthSheet = $spreadsheet->createSheet();
        $fourthSheet->setTitle('IB Liquid');
        $currentRow4 = 1;

        // Set data untuk lembar kerja produk lolos
        $this->setSheetDataProductLolos($fourthSheet, $getProductLolos, $currentRow4, $totalOldPriceLolos);


        // ========================================= Buat lembar kerja baru untuk produk abnormal =====================================

        $thirdSheet = $spreadsheet->createSheet();
        $thirdSheet->setTitle('Abnormal');
        $currentRow3 = 1;

        // Set data untuk lembar kerja produk abnormal
        $this->setSheetDataProductAbnormal($thirdSheet, $getProductAbnormal, $currentRow3, $totalOldPriceAbnormal);



        $firstItem = $checkHistory->first();

        $writer = new Xlsx($spreadsheet);
        $fileName = $firstItem->base_document;
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        // Create exports directory if not exist
        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "File siap diunduh.", $downloadUrl);
        // response()->json(['status' => true, 'message' => "", 'downloadUrl' => $downloadUrl]);
    }

    private function setSheetHeaderProductDamaged($sheet, $headers, &$currentRow)
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $currentRow, $header);
        }
    }

    private function setSheetDataProductDamaged($sheet, $data, &$currentRow, $totalOldPrice)
    {
        // Set header
        $this->setSheetHeaderProductDamaged($sheet, [
            'Code Document', 'Old Barcode', 'New Barcode', 'Name Product', 'Keterangan', 'Qty', 'Unit Price'
        ], $currentRow);

        foreach ($data as $item) {
            // Increment currentRow for the data
            $currentRow++;

            $sheet->setCellValueByColumnAndRow(1, $currentRow, $item->code_document);
            $sheet->setCellValueByColumnAndRow(2, $currentRow, $item->old_barcode_product);
            $sheet->setCellValueByColumnAndRow(3, $currentRow, $item->new_barcode_product);
            $sheet->setCellValueByColumnAndRow(4, $currentRow, $item->new_name_product);
            $sheet->setCellValueByColumnAndRow(5, $currentRow, $item->damaged_value);
            $sheet->setCellValueByColumnAndRow(6, $currentRow, $item->new_quantity_product);
            $sheet->setCellValueByColumnAndRow(7, $currentRow, $item->old_price_product);
        }

        // Menambahkan total harga produk rusak di akhir lembar kerja
        $currentRow++;
        $sheet->setCellValueByColumnAndRow(9, $currentRow, 'Total Price');
        $sheet->setCellValueByColumnAndRow(10, $currentRow, $totalOldPrice);
    }

    private function setSheetHeaderProductLolos($sheet, $headers, &$currentRow)
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $currentRow, $header);
        }
    }

    private function setSheetDataProductLolos($sheet, $data, &$currentRow, $totalOldPrice)
    {
        // Set header
        $this->setSheetHeaderProductLolos($sheet, [
            'Code Document', 'Old Barcode', 'New Barcode', 'Name Product', 'Keterangan', 'Qty', 'Unit Price', 'Category', 'Diskon', 'After Diskon'
        ], $currentRow);

        foreach ($data as $item) {
            // Pindah ke baris berikutnya untuk setiap item
            $currentRow++;
            $diskon = (($item->old_price_product - $item->new_price_product) / $item->old_price_product) * 100;

            $sheet->setCellValueByColumnAndRow(1, $currentRow, $item->code_document);
            $sheet->setCellValueByColumnAndRow(2, $currentRow, $item->old_barcode_product);
            $sheet->setCellValueByColumnAndRow(3, $currentRow, $item->new_barcode_product);
            $sheet->setCellValueByColumnAndRow(4, $currentRow, $item->new_name_product);
            $sheet->setCellValueByColumnAndRow(5, $currentRow, $item->lolos_value); // Menggunakan lolos_value sebagai keterangan
            $sheet->setCellValueByColumnAndRow(6, $currentRow, $item->new_quantity_product);
            $sheet->setCellValueByColumnAndRow(7, $currentRow, $item->old_price_product);
            $sheet->setCellValueByColumnAndRow(8, $currentRow, $item->new_category_product); // Kolom kategori
            $sheet->setCellValueByColumnAndRow(9, $currentRow, $diskon);
            $sheet->setCellValueByColumnAndRow(10, $currentRow, $item->new_price_product); // Harga setelah diskon

        }

        $currentRow++;
        $sheet->setCellValueByColumnAndRow(12, $currentRow, 'Total Price');
        $sheet->setCellValueByColumnAndRow(13, $currentRow, $totalOldPrice);
    }

    private function setSheetHeaderProductAbnormal($sheet, $headers, &$currentRow)
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, $currentRow, $header);
        }
    }

    private function setSheetDataProductAbnormal($sheet, $data, &$currentRow, $totalOldPrice)
    {
        // Set header
        $this->setSheetHeaderProductAbnormal($sheet, [
            'Code Document', 'Old Barcode', 'New Barcode', 'Name Product', 'Keterangan', 'Qty', 'Unit Price'
        ], $currentRow);

        foreach ($data as $item) {
            $currentRow++;
            $sheet->setCellValueByColumnAndRow(1, $currentRow, $item->code_document);
            $sheet->setCellValueByColumnAndRow(2, $currentRow, $item->old_barcode_product);
            $sheet->setCellValueByColumnAndRow(3, $currentRow, $item->new_barcode_product);
            $sheet->setCellValueByColumnAndRow(4, $currentRow, $item->new_name_product);
            $sheet->setCellValueByColumnAndRow(5, $currentRow, $item->abnormal_value);
            $sheet->setCellValueByColumnAndRow(6, $currentRow, $item->new_quantity_product);
            $sheet->setCellValueByColumnAndRow(7, $currentRow, $item->old_price_product);
        }

        // Menambahkan total harga produk abnormal di akhir lembar kerja
        $currentRow++;
        $sheet->setCellValueByColumnAndRow(9, $currentRow, 'Total Price');
        $sheet->setCellValueByColumnAndRow(10, $currentRow, $totalOldPrice);
    }
}
