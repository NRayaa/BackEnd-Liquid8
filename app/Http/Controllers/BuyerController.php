<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BuyerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->has('q')) {
            $buyer = Buyer::when(request()->q, function ($query) {
                $query
                    ->where('name_buyer', 'like', '%' . request()->q . '%')
                    ->orWhere('phone_buyer', 'like', '%' . request()->q . '%')
                    ->orWhere('address_buyer', 'like', '%' . request()->q . '%')
                    ->orWhere('type_buyer', 'like', '%' . request()->q . '%');
            })->latest()->paginate(10);
        } else {
            $buyer = Buyer::latest()->paginate(10);
        }
        $resource = new ResponseResource(true, "List data buyer", $buyer);
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
                'name_buyer' => 'required',
                'phone_buyer' => 'required|numeric',
                'address_buyer' => 'required',
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        try {
            $buyer = Buyer::create([
                'name_buyer' => $request->name_buyer,
                'phone_buyer' => $request->phone_buyer,
                'address_buyer' => $request->address_buyer,
                'type_buyer' => 'Biasa',
                'amount_transaction_buyer' => 0,
                'amount_purchase_buyer' => 0,
                'avg_purchase_buyer' => 0,
            ]);
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $buyer);
        } catch (Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }

        return $resource->response();
    }

    /**
     * Display the specified resource.
     */
    public function show(Buyer $buyer)
    {
        $resource = new ResponseResource(true, "Data buyer", $buyer->load('buyerPoint'));
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Buyer $buyer)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name_buyer' => 'required',
                'phone_buyer' => 'required|numeric',
                'address_buyer' => 'required',
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        try {
            $buyer->update(
                [
                    'name_buyer' => $request->name_buyer,
                    'phone_buyer' => $request->phone_buyer,
                    'address_buyer' => $request->address_buyer,
                ]
            );
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $buyer);
        } catch (Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }

        return $resource->response();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Buyer $buyer)
    {
        try {
            $buyer->delete();
            $resource = new ResponseResource(true, "Data berhasil di hapus!", $buyer);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di hapus!", $e->getMessage());
        }
        return $resource->response();
    }

    public function addBuyerPoint(Buyer $buyer, Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'point_buyer' => 'required|numeric',
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $buyer->update([
                'point_buyer' => $buyer->point_buyer + $request->point_buyer,
            ]);

            logUserAction($request->point_buyer, $request->user(), 'outbound/buyer/detail', 'Menambah Poin Buyer');

            $resource = new ResponseResource(
                true,
                'Poin buyer berhasil ditambahkan',
                $buyer
            );
            return $resource->response();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", null);
            return $resource->response()->setStatusCode(422);
        }
    }

    public function reduceBuyerPoint(Buyer $buyer, Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'point_buyer' => 'required|numeric',
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        if ($buyer->point_buyer < $request->point) {
            $resource = new ResponseResource(
                false,
                'Poin buyer tidak cukup',
                null
            );
            return $resource->response()->setStatusCode(422);
        }

        try {
            $buyer->update([
                'point_buyer' => $buyer->point_buyer - $request->point_buyer,
            ]);

            logUserAction($request->point_buyer, $request->user(), 'outbound/buyer/detail', 'Mengurangi Poin Buyer');

            $resource = new ResponseResource(
                true,
                'Poin buyer berhasil dikurangi',
                $buyer
            );
            return $resource->response();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", null);
            return $resource->response()->setStatusCode(422);
        }
    }

    public function exportBuyers()
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        // Membuat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'ID',
            'name_buyer',
            'phone_buyer',
            'address_buyer',
            'Created At',
            'Updated At'
        ];

        // Menuliskan headers ke sheet
        $columnIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        $rowIndex = 2;

        Buyer::chunk(1000, function ($buyers) use ($sheet, &$rowIndex) {
            foreach ($buyers as $buyer) {
                $sheet->setCellValueByColumnAndRow(1, $rowIndex, $buyer->id);
                $sheet->setCellValueByColumnAndRow(2, $rowIndex, $buyer->name_buyer);
                $sheet->setCellValueByColumnAndRow(3, $rowIndex, $buyer->phone_buyer);
                $sheet->setCellValueByColumnAndRow(4, $rowIndex, $buyer->address_buyer);
                $sheet->setCellValueByColumnAndRow(5, $rowIndex, $buyer->created_at);
                $sheet->setCellValueByColumnAndRow(6, $rowIndex, $buyer->updated_at);
                $rowIndex++;
            }
        });


        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'buyers_export.xlsx';
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
