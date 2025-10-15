<?php

namespace App\Http\Controllers;

use App\Models\New_product;
use App\Models\PaletFilter;
use Illuminate\Http\Request;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Validator;

class PaletFilterController extends Controller
{
    /**
     * Display a listing of the resource. 
     */
    public function index()
    {
        $userId = auth()->id();

        $product_filtersbyUser = PaletFilter::where('user_id', $userId)->get();

        $totalNewPriceWithCategory = $product_filtersbyUser->whereNotNull('new_category_product')->sum('new_price_product');
        $totalOldPriceWithoutCategory = $product_filtersbyUser->whereNull('new_category_product')->sum('old_price_product');
        $totalOldPriceWithCategory = $product_filtersbyUser->whereNotNull('new_category_product')->sum('old_price_product');

        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory;

        $totalOldPrice = $totalOldPriceWithCategory + $totalOldPriceWithoutCategory;

        $product_filters = PaletFilter::where('user_id', $userId)->paginate(100);

        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'total_old_price' => $totalOldPrice,
            'data' => $product_filters,
        ]);
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
    public function store($barcode)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product = New_product::where('new_barcode_product', $barcode)->first();
            if (!$product) {
                $product = StagingProduct::where('new_barcode_product', $barcode)->firstOrFail();
            }
            $product->user_id = $userId;
            $productFilter = PaletFilter::create($product->toArray());

            if ($product->delete()) {
                DB::commit();
            return new ResponseResource(true, "berhasil menambah list product palet", $productFilter);
            } else {
                DB::rollBack();
                return (new ResponseResource(false, "id tidak ada.", $productFilter))->response()->setStatusCode(404);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function bulkUpload(Request $request)
    {
        // Validasi file upload
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        $userId = auth()->id();
        
        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Mencari kolom barcode (case insensitive)
            $barcodeColumn = null;
            $headerRow = $worksheet->getRowIterator(1, 1)->current();
            $cellIterator = $headerRow->getCellIterator();
            
            foreach ($cellIterator as $cell) {
                $headerValue = strtolower(trim($cell->getValue()));
                if ($headerValue === 'barcode') {
                    $barcodeColumn = $cell->getColumn();
                    break;
                }
            }
            
            if (!$barcodeColumn) {
                DB::rollBack();
                return response()->json(['message' => 'Kolom "barcode" tidak ditemukan di file Excel'], 422);
            }
            
            $barcodes = [];
            $missingBarcodes = [];
            $duplicateBarcodes = [];
            
            // TAHAP 1: Validasi semua barcode terlebih dahulu
            $highestRow = $worksheet->getHighestRow();
            for ($row = 2; $row <= $highestRow; $row++) {
                $barcodeValue = trim($worksheet->getCell($barcodeColumn . $row)->getValue());
                
                if (empty($barcodeValue)) {
                    continue; // Skip baris kosong
                }
                
                // Cari produk berdasarkan barcode
                $product = New_product::where('new_barcode_product', $barcodeValue)->first();
                if (!$product) {
                    $product = StagingProduct::where('new_barcode_product', $barcodeValue)->first();
                }
                
                if (!$product) {
                    $missingBarcodes[] = [
                        'row' => $row,
                        'barcode' => $barcodeValue
                    ];
                } else {
                    // Cek apakah sudah ada di PaletFilter
                    $existingFilter = PaletFilter::where('new_barcode_product', $barcodeValue)->first();
                    if ($existingFilter) {
                        $duplicateBarcodes[] = [
                            'row' => $row,
                            'barcode' => $barcodeValue
                        ];
                    } else {
                        $barcodes[] = [
                            'row' => $row,
                            'barcode' => $barcodeValue,
                            'product' => $product
                        ];
                    }
                }
            }
            
            // Jika ada barcode yang tidak ditemukan, return error tanpa memproses apapun
            if (!empty($missingBarcodes)) {
                DB::rollBack();
                
                $errorMessage = "Ditemukan " . count($missingBarcodes) . " barcode yang tidak ada di table";
                $missingList = [];
                foreach ($missingBarcodes as $missing) {
                    $missingList[] = "Baris {$missing['row']}: {$missing['barcode']}";
                }
                
                $resource = new ResponseResource(false, $errorMessage, [
                    'missing_barcodes' => $missingList,
                    'missing_count' => count($missingBarcodes)
                ]);
                return $resource->response()->setStatusCode(422);
            }
            
            // Jika ada barcode yang sudah duplikasi, return error tanpa memproses apapun
            if (!empty($duplicateBarcodes)) {
                DB::rollBack();
                
                $errorMessage = "Ditemukan " . count($duplicateBarcodes) . " barcode yang sudah ada di palet filter";
                $duplicateList = [];
                foreach ($duplicateBarcodes as $duplicate) {
                    $duplicateList[] = "Baris {$duplicate['row']}: {$duplicate['barcode']}";
                }
                
                $resource = new ResponseResource(false, $errorMessage, [
                    'duplicate_barcodes' => $duplicateList,
                    'duplicate_count' => count($duplicateBarcodes)
                ]);
                return $resource->response()->setStatusCode(422);
            }
            
            // TAHAP 2: Proses input jika semua barcode valid dan tidak duplikasi
            $processedCount = 0;
            $errorCount = 0;
            $errors = [];
            
            foreach ($barcodes as $barcodeData) {
                $barcodeValue = $barcodeData['barcode'];
                $product = $barcodeData['product'];
                $row = $barcodeData['row'];
                
                try {
                    $product->user_id = $userId;
                    $productFilter = PaletFilter::create($product->toArray());
                    
                    if ($product->delete()) {
                        $processedCount++;
                    } else {
                        $errors[] = "Baris {$row}: Gagal menghapus produk dengan barcode '{$barcodeValue}'";
                        $errorCount++;
                    }
                    
                } catch (\Exception $e) {
                    $errors[] = "Baris {$row}: Error processing barcode '{$barcodeValue}' - " . $e->getMessage();
                    $errorCount++;
                }
            }
            
            DB::commit();
            
            $message = "Upload selesai. {$processedCount} produk berhasil diproses";
            if ($errorCount > 0) {
                $message .= ", {$errorCount} error ditemukan";
            }
            
            return new ResponseResource(true, $message, [
                'processed_count' => $processedCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);
            
        } catch (ReaderException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error membaca file Excel: ' . $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    /**
     * Display the specified resource.
     */
    public function show(PaletFilter $paletFilter)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PaletFilter $paletFilter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PaletFilter $paletFilter)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $product_filter = PaletFilter::findOrFail($id);
            New_product::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product palet", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
