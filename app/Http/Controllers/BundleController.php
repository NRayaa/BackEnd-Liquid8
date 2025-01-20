<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\Product_Bundle;
use App\Models\ProductInput;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class BundleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
    
        $bundles = Bundle::whereNull('type')->orWhere('type', 'type1')->latest()->with('product_bundles');
    
        if ($query) {
            $bundles->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('product_bundles', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    });
            });
        }
    
        $paginatedBundles = $bundles->paginate(50);
    
        return new ResponseResource(true, "list bundle", $paginatedBundles);
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
    public function show(Request $request, Bundle $bundle)
    {
        $query = $request->input('q');
        $bundle->load(['product_bundles' => function ($productBundles) use ($query) {
            if (!empty($query)) {
                $productBundles->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
            }
        }]);
        return new ResponseResource(true, "detail bundle", $bundle);
    }

    /**
     * Show the form for editing the specified resource.
     */
    
    public function edit(Bundle $bundle)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bundle $bundle)
    {
        $validator = Validator::make($request->all(), [
            'name_bundle' => 'required',
            'category' => 'nullable',
            'total_price_bundle' => 'required|numeric',
            'total_price_custom_bundle' => 'required|numeric',
            'total_product_bundle' => 'nullable',
            'name_color' => 'nullable'
        ]);
    
        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }
    
        DB::beginTransaction();
        try {
            $productBundle = Product_Bundle::where('bundle_id', $bundle->id)->get();

            if ($productBundle) {
                $qty = $request->total_product_bundle ?? count($productBundle);
            } else {
                $qty = $request->total_product_bundle ?? 0;
            }
            
            // Melakukan update pada data bundle
            $bundle->update([
                'name_bundle' => $request->name_bundle,
                'category' => $request->has('category') ? $request->category : null,
                'total_price_bundle' => $request->total_price_bundle,
                'total_price_custom_bundle' => $request->total_price_custom_bundle,
                'total_product_bundle' => $qty, 
                'name_color' => $request->has('name_color') ? $request->name_color : null 
            ]);
    
            DB::commit();
            return new ResponseResource(true, "Bundle berhasil di edit", $bundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Bundle gagal di edit" . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Bundle gagal di edit', 'error' => $e->getMessage()], 500);
        }
    }
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bundle $bundle)
    {
        DB::beginTransaction();
        try {
            $productBundles = $bundle->product_bundles;

            foreach ($productBundles as $product) {
                New_product::create([
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'display',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'display_price' => $product->display_price,
                    'new_discount' => $product->new_discount,
                    'type' => $product->type
                ]);

                $product->delete();
            }

            $bundle->delete();

            DB::commit();
            return new ResponseResource(true, "Produk bundle berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function unbundleScan(Bundle $bundle)
    {
        DB::beginTransaction();
        try {
            $productBundles = $bundle->product_bundles;

            foreach ($productBundles as $product) {
                ProductInput::create([
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'display',
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'display_price' => $product->display_price,
                    'new_discount' => $product->new_discount,
                    'type' => $product->type
                ]);

                $product->delete();
            }

            $bundle->delete();

            DB::commit();
            return new ResponseResource(true, " Unbundle berhasil ", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function listBundleScan(Request $request)
    {
        $query = $request->input('q');
    
        $bundles = Bundle::Where('type', 'type2')->latest()->with('product_bundles');
    
        if ($query) {
            $bundles->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('product_bundles', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    });
            });
        }
    
        $paginatedBundles = $bundles->paginate(50);
    
        return new ResponseResource(true, "list bundle", $paginatedBundles);
    }

    public function exportBundles()
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        // Membuat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers untuk bundle
        $bundleHeaders = [
            'name_bundle',
            'total_price_bundle',
            'total_price_custom_bundle',
            'total_product_bundle',
            'product_status',
            'barcode_bundle',
            'category',
            'name_color',
            'id'
        ];

        // Headers untuk product_bundles
        $productBundleHeaders = [
            'bundle_id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product'
        ];

        // Menuliskan headers ke sheet
        $columnIndex = 1;
        foreach ($bundleHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        // Menuliskan header product_bundles di bawah data bundle
        $rowIndex = 2; // Mulai dari baris kedua

        // Mengambil data bundle terbaru dengan relasi product_bundles
        $bundles = Bundle::latest()->with('product_bundles')->get();
        foreach ($bundles as $bundle) {
            $columnIndex = 1;

            // Menuliskan data bundle ke sheet
            foreach ($bundleHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, $rowIndex, $bundle->$header);
                $columnIndex++;
            }
            $rowIndex++;

            // Menuliskan header product_bundles
            $productColumnIndex = 1;
            foreach ($productBundleHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $header);
                $productColumnIndex++;
            }
            $rowIndex++;

            // Menuliskan data product_bundles ke sheet
            if ($bundle->product_bundles) {
                foreach ($bundle->product_bundles as $productBundle) {
                    $productColumnIndex = 1; // Mulai dari kolom pertama
                    foreach ($productBundleHeaders as $header) {
                        $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $productBundle->$header);
                        $productColumnIndex++;
                    }
                    $rowIndex++;
                }
            }
            $rowIndex++; // Baris kosong setelah setiap bundle
        }

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'bundles_export.xlsx';
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

    public function exportBundlesDetail($id)
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        // Membuat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers untuk bundle
        $bundleHeaders = [
            'id',
            'name_bundle',
            'total_price_bundle',
            'total_price_custom_bundle',
            'total_product_bundle',
            'product_status',
            'barcode_bundle',
            'category',
            'name_color',
        ];

        // Headers untuk product_bundles
        $productBundleHeaders = [
            'bundle_id',
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_quality',
            'new_category_product',
            'new_tag_product'
        ];

        // Menuliskan headers untuk bundle ke sheet
        $columnIndex = 1;
        foreach ($bundleHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        // Menuliskan header product_bundles di bawah data bundle
        $rowIndex = 2; // Mulai dari baris kedua

        // Mengambil data bundle terbaru dengan relasi product_bundles
        $bundle = Bundle::with('product_bundles')->where('id', $id)->first();

        if ($bundle) {
            $columnIndex = 1;

            // Menuliskan data bundle ke sheet
            foreach ($bundleHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, $rowIndex, $bundle->$header);
                $columnIndex++;
            }
            $rowIndex++;

            $rowIndex++;
            // Menuliskan header product_bundles
            $productColumnIndex = 1;
            foreach ($productBundleHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $header);
                $productColumnIndex++;
            }
            $rowIndex++;

            // Menuliskan data product_bundles ke sheet
            if ($bundle->product_bundles->isNotEmpty()) {
                foreach ($bundle->product_bundles as $productBundle) {
                    $productColumnIndex = 1; // Mulai dari kolom pertama
                    foreach ($productBundleHeaders as $header) {
                        $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $productBundle->$header);
                        $productColumnIndex++;
                    }
                    $rowIndex++;
                }
            }
            $rowIndex++; // Baris kosong setelah setiap bundle
        } else {
            // Jika tidak ada bundle ditemukan
            $sheet->setCellValueByColumnAndRow(1, 1, 'No data found');
        }

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'exportBndl_' . $bundle->name_bundle . '.xlsx';
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

    public function bundleColor(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {

            $bundle = Bundle::create([
                'name_bundle' => $request->name_bundle,
                'total_price_bundle' => $request->total_price_custom_bundle,
                'total_price_custom_bundle' => $request->total_price_custom_bundle,
                'total_product_bundle' => $request->total_product_bundle,
                'barcode_bundle' => $request->barcode_bundle,
                'category' => $request->category,
                'name_color' => $request->name_color,
            ]);

            $insertData = New_product::where('new_tag_product', $bundle->total_product_bundle)->get();

            // Menggunakan chunk untuk memproses data dalam kelompok 100 item
            $insertData->chunk(100)->each(function ($chunkedData) use ($bundle) {
                // Mapping data untuk disiapkan sebelum insert
                $dataToInsert = $chunkedData->map(function ($item) use ($bundle) {
                    return [
                        'bundle_id' => $bundle->id,
                        'code_document' => $item->code_document,
                        'old_barcode_product' => $item->old_barcode_product,
                        'new_barcode_product' => $item->new_barcode_product,
                        'new_name_product' => $item->new_name_product,
                        'new_quantity_product' => $item->new_quantity_product,
                        'new_price_product' => $item->new_price_product,
                        'old_price_product' => $item->old_price_product,
                        'new_date_in_product' => $item->new_date_in_product,
                        'new_status_product' => 'bundle', 
                        'new_quality' => $item->new_quality,
                        'new_category_product' => $item->new_category_product,
                        'new_tag_product' => $item->new_tag_product,
                        'new_discount' => $item->new_discount,
                        'display_price' => $item->display_price,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'type' => $item->type
                    ];
                })->toArray();

                Product_Bundle::insert($dataToInsert);
            });



            logUserAction($request, $request->user(), "storage/moving_product/create_bundle", "Create bundle color");

            DB::commit();
            return new ResponseResource(true, "Bundle berhasil dibuat", $bundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bundle', 'error' => $e->getMessage()], 500);
        }
    }
}
