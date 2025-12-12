<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\BuyerResource;
use App\Http\Resources\ResponseResource;
use App\Models\BuyerLoyalty;
use App\Models\SaleDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
        $query = Buyer::with(['buyerLoyalty.rank']);

        if (request()->has('q') && !empty(trim(request()->q))) {
            $searchTerm = trim(request()->q);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name_buyer', 'like', '%' . $searchTerm . '%')
                    ->orWhere('phone_buyer', 'like', '%' . $searchTerm . '%')
                    ->orWhere('address_buyer', 'like', '%' . $searchTerm . '%')
                    ->orWhere('type_buyer', 'like', '%' . $searchTerm . '%');
            });
        }

        $buyers = $query->latest()->paginate(10);


        $paginatedArray = $buyers->toArray();
        $paginatedArray['data'] = BuyerResource::collection($buyers->items());

        return new ResponseResource(
            true,
            "List data buyer",
            $paginatedArray
        );
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
                'email' => 'nullable|email|unique:buyers,email',
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
                'email' => $request->email ?? null,
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

        $buyer->load(['buyerPoint', 'buyerLoyalty.rank']);


        $buyerResource = new BuyerResource($buyer);

        $documents = SaleDocument::select(
            'id',
            'buyer_id_document_sale',
            'total_product_document_sale',
            'code_document_sale',
            'total_price_document_sale',
            'created_at',
            'price_after_tax'
        )
            ->where('buyer_id_document_sale', $buyer->id)
            ->paginate(20);


        $responseData = [
            'buyer' => $buyerResource,
            'documents' => $documents
        ];

        return new ResponseResource(true, "Data buyer", $responseData);
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

            logUserAction($request, $request->user(), 'outbound/buyer/detail', 'Menambah Poin Buyer' . $request->point_buyer);

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

            logUserAction($request, $request->user(), 'outbound/buyer/detail', 'Mengurangi Poin Buyer' . $request->point_buyer);

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

        set_time_limit(300);
        ini_set('memory_limit', '512M');


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



        $writer = new Xlsx($spreadsheet);
        $fileName = 'buyers_export.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;


        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);


        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "file diunduh", $downloadUrl);
    }

    public function updateEmail(Buyer $buyer, Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'nullable|email|unique:buyers,email,' . $buyer->id,
            ]
        );

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
        try {
            $buyer->update(
                [
                    'email' => $request->email,
                ]
            );
            $resource = new ResponseResource(true, "Data berhasil ditambahkan!", $buyer);
        } catch (Exception $e) {
            $resource = new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage());
        }

        return $resource->response();
    }

    public function getMonthlyTopBuyers(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'month' => 'required|numeric|min:1|max:12',
            'year' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            $month = $request->month;
            $year = $request->year;

            $topBuyers = SaleDocument::select(
                'buyer_id_document_sale',
                DB::raw('SUM(buyer_point_document_sale) as total_points')
            )
                ->with('buyer:id,name_buyer')
                ->whereHas('buyer')
                ->where('status_document_sale', 'selesai')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->groupBy('buyer_id_document_sale')
                ->orderByDesc('total_points')
                ->limit(3)
                ->get();

            if ($topBuyers->isEmpty()) {
                return new ResponseResource(true, "Belum ada data penjualan pada periode $month-$year", []);
            }

            $result = $topBuyers->map(function ($item, $index) {
                return [
                    'rank' => $index + 1,
                    'buyer_id' => $item->buyer_id_document_sale,
                    'buyer_name' => $item->buyer->name_buyer ?? 'Unknown Buyer',
                    'total_points' => (int) $item->total_points,
                ];
            });

            return new ResponseResource(true, "Top 3 Buyer Periode $month-$year", $result);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server", $e->getMessage()))->response()->setStatusCode(500);
        }
    }
}
