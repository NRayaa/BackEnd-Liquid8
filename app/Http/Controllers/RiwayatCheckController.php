<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Document;
use App\Models\New_product;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|unique:riwayat_checks,code_document',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = Document::where('code_document', $request['code_document'])->first();

        if (!$document) {
            return response()->json(['error' => 'Document tidak ada'], 404);
        }

        if ($document->total_column_in_document == 0) {
            return response()->json(['error' => 'Total data di document tidak boleh 0'], 422);
        }

        $newProducts = New_product::where('code_document', $request['code_document'])->get();

        $totalData = $newProducts->count();
        $totalLolos = 0;
        $totalDamaged = 0;
        $totalAbnormal = 0;
        // $newQualityData = $newProducts->pluck('new_quality')->map(function ($item) {
        //     dd($item);
        //     return json_decode($item, true);
        // });
        
        // foreach ($newProducts->new_quality as $item) {
        //     if (is_array($item)) {
        //         $totalLolos += !empty($item['lolos']) ? 1 : 0;
        //         $totalDamaged += !empty($item['damaged']) ? 1 : 0;
        //         $totalAbnormal += !empty($item['abnormal']) ? 1 : 0;
        //     }
        // }
        
        foreach ($newProducts as $product) {
            $newQualityData = json_decode($product->new_quality, true);

                  if (is_array($newQualityData)) {
                $totalLolos += !empty($newQualityData['lolos']) ? 1 : 0;
                $totalDamaged += !empty($newQualityData['damaged']) ? 1 : 0;
                $totalAbnormal += !empty($newQualityData['abnormal']) ? 1 : 0;
            }
        }
        
        // dd(
        //     $totalLolos,
        //     $totalDamaged,
        //     $totalAbnormal,
        //     $totalData,
        //     $newProducts
        // );
        

        $riwayat_check = RiwayatCheck::create([
            'code_document' => $request['code_document'],
            'total_data' => $document->total_column_in_document,
            'total_data_in' => $totalData,
            'total_data_lolos' => $totalLolos,
            'total_data_damaged' => $totalDamaged,
            'total_data_abnormal' => $totalAbnormal,
            'total_discrepancy' => $document->total_column_in_document - $totalData,

            // persentase
            'precentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
            'percentage_in' => ($totalData / $document->total_column_in_document) * 100,
            'percentage_lolos' => ($totalLolos / $document->total_column_in_document) * 100,
            'percentage_damaged' => ($totalDamaged / $document->total_column_in_document) * 100,
            'percentage_abnormal' => ($totalAbnormal / $document->total_column_in_document) * 100,
            'percentage_discrepancy' => (($document->total_column_in_document - $totalData) / $document->total_column_in_document) * 100,
        ]);

        //update status document
        $code_document = Document::where('code_document', $request['code_document'])->first();
        $code_document->update(['status_document' => 'done']);

        return new ResponseResource(true, "Data berhasil ditambah", $riwayat_check);
    }



    public function show(RiwayatCheck $riwayatCheck)
    {
        return new ResponseResource(true, "Riwayat Check", $riwayatCheck);
    }

    public function getByDocument(Request $request)
    {
        $codeDocument = RiwayatCheck::where('code_document', $request['code_document']);
        return new ResponseResource(true, "Riwayat Check", $codeDocument);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RiwayatCheck $riwayatCheck)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RiwayatCheck $riwayatCheck)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RiwayatCheck $history)
    {
        try {
            $history->delete();
            return new ResponseResource(true, 'data berhasil di hapus', $history);
        } catch (\Exception $e) {
            return new ResponseResource(false, 'data gagal di hapus', null);
        }
    }
}
