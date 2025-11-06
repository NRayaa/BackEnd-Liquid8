<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Category;
use App\Models\Document;
use Illuminate\Http\Request;
use App\Models\BarcodeDamaged;
use App\Models\StagingProduct;
use App\Models\SummarySoCategory;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Resources\ResponseResource;

class BarcodeDamagedController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(BarcodeDamaged $barcodeDamaged)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BarcodeDamaged $barcodeDamaged)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BarcodeDamaged $barcodeDamaged)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BarcodeDamaged $barcodeDamaged)
    {
        //
    }

    public function importExcelToBarcodeDamaged(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ], [
            'file.required' => 'File harus diunggah.',
            'file.file' => 'File yang diunggah tidak valid.',
            'file.mimes' => 'File harus berupa file Excel dengan ekstensi .xlsx atau .xls.',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        DB::beginTransaction();
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Asumsi baris pertama adalah header
            $header = $rows[0];
            $barcodeIndex = array_search('barcode', $header);
            $priceIndex = array_search('price', $header);
            
            if ($barcodeIndex === false) {
                return response()->json(['error' => 'Header Barcode tidak ditemukan'], 422);
            }
            if ($priceIndex === false) {
                return response()->json(['error' => 'Header Price tidak ditemukan'], 422);
            }

            $dataToInsert = [];
            for ($i = 1; $i < count($rows); $i++) {
                $barcode = isset($rows[$i][$barcodeIndex]) ? trim($rows[$i][$barcodeIndex]) : null;
                $price = isset($rows[$i][$priceIndex]) ? trim($rows[$i][$priceIndex]) : 0;
                
                if ($barcode) {
                    $dataToInsert[] = [
                        'code_document' => '0002/11/2025',
                        'old_barcode_product' => $barcode,
                        'old_price_product' => $price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($dataToInsert)) {
                \App\Models\BarcodeDamaged::insert($dataToInsert);
            }

            DB::commit();
            return new \App\Http\Resources\ResponseResource(true, 'Data berhasil diimport', null);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }
}
