<?php

namespace App\Http\Controllers;

use App\Models\Palet;
use App\Models\Category;
use App\Models\Warehouse;
use App\Models\PaletBrand;
use App\Models\PaletImage;
use App\Models\New_product;
use App\Models\PaletFilter;
use App\Models\PaletProduct;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use App\Models\ProductStatus;
use App\Models\StagingProduct;
use App\Models\ProductCondition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;



class PaletController extends Controller
{
    public function display(Request $request)
    {
        $query = $request->input('q');
        $page = $request->input('page');

        // Tentukan kolom yang sama untuk kedua query
        $columns = [
            'id',
            'new_name_product',
            'new_barcode_product',
            'old_barcode_product',
            'new_category_product',
            'new_status_product',
            'new_tag_product',
            'new_price_product',
            'created_at',
            'new_quality'
        ];

        $newProductsQuery = New_product::select($columns)
            ->where('new_status_product', 'display')
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereNull('new_tag_product');

        $stagingProductsQuery = StagingProduct::select($columns)
            ->whereNotIn('new_status_product', ['dump', 'expired', 'sale', 'migrate', 'repair'])
            ->whereNull('new_tag_product');

        if ($query) {
            $newProductsQuery->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $query . '%');
            });

            $stagingProductsQuery->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $query . '%');
            });

            $page = 1;
        }

        $products = $newProductsQuery->unionAll($stagingProductsQuery)
            ->orderBy('created_at', 'desc')
            ->paginate(33, ['*'], 'page', $page);

        return new ResponseResource(true, "Data produk dengan status display.", $products);
    }

    public function index(Request $request)
    {
        $query = $request->input('q');
        $palets = Palet::latest()
            ->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('paletProducts', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    });
            })->paginate(20);
        return new ResponseResource(true, "list palet", $palets);
    }

    public function index2(Request $request)
    {
        $query = $request->input('q');
        $palets = Palet::latest()->with(['paletImages', 'paletProducts', 'paletBrands'])
            ->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('paletProducts', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    });
            })->paginate(20);
        return new ResponseResource(true, "list palet", $palets);
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
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            // Validasi request
            $validator = Validator::make($request->all(), [
                'images' => 'array|nullable',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:5120',
                'name_palet' => 'required|string',
                'category_palet' => 'nullable|string',
                'total_price_palet' => 'required|numeric',
                'total_product_palet' => 'required|integer',
                'file_pdf' => 'nullable|mimes:pdf|max:100',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'is_sale' => 'boolean',
                'category_id' => 'nullable|exists:categories,id',
                'product_brand_ids' => 'array|nullable',
                'product_brand_ids.*' => 'exists:product_brands,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                'product_condition_id' => 'required|exists:product_conditions,id',
                'product_status_id' => 'required|exists:product_statuses,id',
                'discount' => 'nullable'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $validatedData = [];

            if ($request->hasFile('file_pdf')) {
                $file = $request->file('file_pdf');
                $filename = time() . '_' . $file->getClientOriginalName();
                $pdfPath = $file->storeAs('palets_pdfs', $filename, 'public');
                $validatedData['file_pdf'] = asset('storage/' . $pdfPath);
            } else {
                $validatedData['file_pdf'] = null;
            }

            $category = Category::find($request['category_id']) ?: null;
            $warehouse = Warehouse::findOrFail($request['warehouse_id']);
            $productStatus = ProductStatus::findOrFail($request['product_status_id']);
            $productCondition = ProductCondition::findOrFail($request['product_condition_id']);

            // Create Palet
            $palet = Palet::create([
                'name_palet' => $request['name_palet'],
                'category_palet' => $category->name_category ?? '',
                'total_price_palet' => $request['total_price_palet'],
                'total_product_palet' => $request['total_product_palet'],
                'palet_barcode' => barcodePalet($userId),
                'file_pdf' => $validatedData['file_pdf'] ?? null,
                'description' => $request['description'] ?? null,
                'is_active' => $request['is_active'] ?? false,
                'warehouse_name' => $warehouse->nama,
                'product_condition_name' => $productCondition->condition_name,
                'product_status_name' => $productStatus->status_name,
                'is_sale' => $request['is_sale'] ?? false,
                'category_id' => $request['category_id'],
                'warehouse_id' => $request['warehouse_id'],
                'product_condition_id' => $request['product_condition_id'],
                'product_status_id' => $request['product_status_id'],
                'discount' => $request['discount'],
            ]);


            // Handle multiple image uploads
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imageName = $image->hashName();
                    $imagePath = $image->storeAs('product-images', $imageName, 'public');

                    PaletImage::create([
                        'palet_id' => $palet->id,
                        'filename' => $imageName
                    ]);
                }
            }

            $brands = $request->input('product_brand_ids');
            if ($brands) {
                $createdBrands = [];
                foreach ($brands as $brandId) {
                    $paletBrandName = ProductBrand::findOrFail($brandId)->brand_name;
                    $paletBrand = PaletBrand::create([
                        'palet_id' => $palet->id,
                        'brand_id' => $brandId,
                        'palet_brand_name' => $paletBrandName,
                    ]);
                    $createdBrands[] = $paletBrand;
                }
            }

            $userId = auth()->id();
            $product_filters = PaletFilter::where('user_id', $userId)->get();

            $insertData = $product_filters->map(function ($product) use ($palet) {
                return [
                    'palet_id' => $palet->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => $product->new_status_product,
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            PaletProduct::insert($insertData);

            PaletFilter::where('user_id', $userId)->delete();

            DB::commit();

            return new ResponseResource(true, "Data palet berhasil ditambahkan", $palet->load(['paletImages', 'paletBrands']));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store palet: ' . $e->getMessage());
            return new ResponseResource(false, "Data gagal ditambahkan", null);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(Request $request, Palet $palet)
    {
        $query = $request->input('q');
        $palet->load(['paletImages', 'paletProducts', 'paletBrands' => function ($productPalet) use ($query) {
            if (!empty($query)) {
                $productPalet->where('new_name_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
            }
        }]);
        if ($palet->discount == null) {
            $palet->discount = 0;
        }
        $palet->total_harga_lama = $palet->paletProducts->sum('old_price_product');

        return new ResponseResource(true, "list product", $palet);
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Palet $palet)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Palet $palet)
    {
        DB::beginTransaction();

        try {
            // Validasi request
            $validator = Validator::make($request->all(), [
                'images' => 'array|nullable',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:5120',
                'name_palet' => 'required|string',
                'category_palet' => 'nullable|string',
                'total_price_palet' => 'required|numeric',
                'total_product_palet' => 'required|integer',
                'palet_barcode' => 'required|string|unique:palets,palet_barcode,' . $palet->id,
                'file_pdf' => 'nullable|mimes:pdf|max:100',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'is_sale' => 'boolean',
                'category_id' => 'nullable|exists:categories,id',
                'product_brand_ids' => 'nullable',
                'product_brand_ids.*' => 'exists:product_brands,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                'product_condition_id' => 'required|exists:product_conditions,id',
                'product_status_id' => 'required|exists:product_statuses,id',
                'discount' => 'nullable'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $category = Category::find($request['category_id']) ?: null;
            $warehouse = Warehouse::findOrFail($request['warehouse_id']);
            $productStatus = ProductStatus::findOrFail($request['product_status_id']);
            $productCondition = ProductCondition::findOrFail($request['product_condition_id']);

            $validatedData = [];
            if ($request->hasFile('file_pdf')) {
                // Cek jika file yang dikirim berbeda dengan file lama
                if ($palet->file_pdf && $palet->file_pdf !== $request->file('file_pdf')->getClientOriginalName()) {
                    // Hapus file PDF lama jika ada dan nama file berbeda
                    Storage::disk('public')->delete('palets_pdfs/' . $palet->file_pdf);
                }

                // Ambil file baru dan simpan
                $file = $request->file('file_pdf');
                $filename = $file->getClientOriginalName();
                $pdfPath = $file->storeAs('palets_pdfs', $filename, 'public');

                // Simpan path file ke validatedData
                $validatedData['file_pdf'] = asset('storage/' . $pdfPath);
            } else {
                $validatedData['file_pdf'] = $palet->file_pdf;
            }
            $palet->update([
                'name_palet' => $request['name_palet'],
                'category_palet' => $category->name_category ?? '',
                'total_price_palet' => $request['total_price_palet'],
                'total_product_palet' => $request['total_product_palet'],
                'palet_barcode' => $request['palet_barcode'],
                'file_pdf' => $validatedData['file_pdf'] ?? null,
                'description' => $request['description'] ?? null,
                'is_active' => $request['is_active'] ?? false,
                'warehouse_name' => $warehouse->nama,
                'product_condition_name' => $productCondition->condition_name,
                'product_status_name' => $productStatus->status_name,
                'is_sale' => $request['is_sale'] ?? false,
                'category_id' => $request['category_id'],
                'warehouse_id' => $request['warehouse_id'],
                'product_condition_id' => $request['product_condition_id'],
                'product_status_id' => $request['product_status_id'],
                'discount' => $request['discount']
            ]);

            if ($request->hasFile('images')) {
                $oldImages = PaletImage::where('palet_id', $palet->id)->get();
                foreach ($oldImages as $oldImage) {
                    Storage::disk('public')->delete('product-images/' . $oldImage->filename);
                    $oldImage->delete();
                }

                // Simpan gambar baru
                foreach ($request->file('images') as $image) {
                    $imageName = $image->hashName();
                    $image->storeAs('product-images', $imageName, 'public');

                    PaletImage::create([
                        'palet_id' => $palet->id,
                        'filename' => $imageName
                    ]);
                }
            }

            $brands = $request->input('product_brand_ids');

            // Deteksi array atau string
            if (!is_array($brands)) {
                $brands = trim($brands, '"');
                $brands = explode(',', $brands);
            }

            // Proses data
            if ($brands) {
                $updatedBrands = [];

                $brandCurrent = PaletBrand::where('palet_id', $palet->id)->pluck('brand_id')->toArray();
                $brandToDeletes = array_diff($brandCurrent, $brands);
                PaletBrand::where('palet_id', $palet->id)->whereIn('brand_id', $brandToDeletes)->delete();

                foreach ($brands as $brandId) {
                    $paletBrandName = ProductBrand::findOrFail($brandId)->brand_name;
                    if (!$paletBrandName) {
                        return (new ResponseResource(false, "Data gagal diperbarui, id brand tidak ada", $brandId))->response()->setStatusCode(500);
                    }
                    $paletBrand = PaletBrand::updateOrCreate(
                        ['palet_id' => $palet->id, 'brand_id' => $brandId],
                        ['palet_brand_name' => $paletBrandName]
                    );

                    $updatedBrands[] = $paletBrand;
                }
            }

            DB::commit();

            return new ResponseResource(true, "Data palet berhasil diperbarui", $palet->load(['paletImages', 'paletBrands']));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update palet: ' . $e->getMessage());
            return (new ResponseResource(false, "Data gagal diperbarui", null))->response()->setStatusCode(500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Palet $palet)
    {
        DB::beginTransaction();
        try {
            $productPalet = $palet->paletProducts;
            if ($productPalet) {
                foreach ($productPalet as $product) {
                    New_product::create([
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->old_barcode_product,
                        'new_barcode_product' => $product->new_barcode_product,
                        'new_name_product' => $product->new_name_product,
                        'new_quantity_product' => $product->new_quantity_product,
                        'new_price_product' => $product->new_price_product,
                        'old_price_product' => $product->old_price_product,
                        'new_date_in_product' => $product->new_date_in_product,
                        'new_status_product' => $product->new_status_product,
                        'new_quality' => $product->new_quality,
                        'new_category_product' => $product->new_category_product,
                        'new_tag_product' => $product->new_tag_product,
                        'new_discount' => $product->new_discount,
                        'display_price' => $product->display_price,
                        'type' => $product->type ?? null,
                        'user_id' => $product->user_id ?? null
                    ]);

                    $product->delete();
                }
            }
            $oldImages = PaletImage::where('palet_id', $palet->id)->get();
            foreach ($oldImages as $oldImage) {
                Storage::disk('public')->delete('product-images/' . $oldImage->filename);
                $oldImage->delete();
            }

            $paletBrands = PaletBrand::where('palet_id', $palet->id)->get();
            foreach ($paletBrands as $paletBrand) {
                $paletBrand->delete();
            }

            $palet->delete();

            DB::commit();
            return new ResponseResource(true, "palet berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus palet: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus palet', 'error' => $e->getMessage()], 500);
        }
    }


    public function exportpaletsDetail(Request $request, $id)
    {
        if (!in_array($request->input('type'), ['pdf', 'excel'])) {
            return new ResponseResource(false, "Invalid export type", null);
        }

        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $palet = Palet::with('paletProducts')->where('id', $id)->first();
        if (!$palet) {
            return (new ResponseResource(false, "Palet not found", null))->response()->setStatusCode(404);
        }

        $paletHeaders = [
            'id',
            'name_palet',
            'category_palet',
            'total_price_palet',
            'total_product_palet',
            'palet_barcode',
            'total_harga_lama',
            'is_sale',
            'warehouse_name',
            'product_condition_name',
            'product_status_name',
            'discount'
        ];

        $paletProductsHeaders = [
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
            'new_tag_product',
            'new_discount',
            'display_price'
        ];

        $fileName = 'Palet_' . $palet->palet_barcode . '.' . ($request->input('type') === 'pdf' ? 'pdf' : 'xlsx');
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        if ($request->input('type') === 'pdf') {
            // Gunakan Facade PDF
            $pdf = Pdf::loadView('exports.palet-detail', [
                'palet' => $palet,
                'paletHeaders' => $paletHeaders,
                'paletProductsHeaders' => $paletProductsHeaders
            ]);

            $fileName = 'Palet_' . $palet->palet_barcode . '.pdf';
            $publicPath = 'exports';
            $filePath = public_path($publicPath) . '/' . $fileName;

            if (!file_exists(public_path($publicPath))) {
                mkdir(public_path($publicPath), 0777, true);
            }

            $pdf->save($filePath);
            $downloadUrl = url($publicPath . '/' . $fileName);
        } else {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $columnIndex = 1;
            foreach ($paletHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
                $columnIndex++;
            }

            $rowIndex = 2;
            $columnIndex = 1;

            foreach ($paletHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex, $rowIndex, $palet->$header);
                $columnIndex++;
            }
            $rowIndex++;

            $rowIndex++;
            $productColumnIndex = 1;
            foreach ($paletProductsHeaders as $header) {
                $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $header);
                $productColumnIndex++;
            }
            $rowIndex++;

            if ($palet->paletProducts->isNotEmpty()) {
                foreach ($palet->paletProducts as $productPalet) {
                    $productColumnIndex = 1;
                    foreach ($paletProductsHeaders as $header) {
                        $sheet->setCellValueByColumnAndRow($productColumnIndex, $rowIndex, $productPalet->$header);
                        $productColumnIndex++;
                    }
                    $rowIndex++;
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);
        }

        $downloadUrl = url($publicPath . '/' . $fileName);
        return new ResponseResource(true, "unduh", $downloadUrl);
    }

    public function updateCategoryPalet(Request $request)
    {
        $palets = Palet::all();

        foreach ($palets as $palet) {
            $category = Category::where('name_category', $palet->category_palet)->first();

            if ($category) {
                $palet->category_id = $category->id;
                $palet->save();
            }
        }
        return new ResponseResource(true, "berhasil di update", []);
    }

    public function palet_select(Request $request)
    {
        $categories = Category::latest()->get();
        $warehouses = Warehouse::latest()->get();
        $productBrands = ProductBrand::latest()->get();
        $productConditions = ProductCondition::latest()->get();
        $productStatus = ProductStatus::latest()->get();

        return new ResponseResource(true, "list select", [
            'categories' => $categories,
            'warehouses' => $warehouses,
            'product_brands' => $productBrands,
            'product_conditions' => $productConditions,
            'product_status' => $productStatus
        ]);
    }

    public function delete_pdf_palet($id_palet)
    {
        $pdf_palet = Palet::find($id_palet);

        if (!$pdf_palet) {
            return (new ResponseResource(false, "ID palet tidak ditemukan", []))
                ->response()
                ->setStatusCode(404);
        }

        $filePath = str_replace('/storage/', '', parse_url($pdf_palet->file_pdf, PHP_URL_PATH));

        if ($filePath && Storage::exists($filePath)) {
            Storage::delete($filePath);
        }

        $pdf_palet->file_pdf = null;
        $pdf_palet->save();

        return (new ResponseResource(true, "Berhasil menghapus PDF", $pdf_palet))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy_with_product(Palet $palet)
    {
        DB::beginTransaction();
        try {
            $palet->paletProducts()->delete();

            $oldImages = PaletImage::where('palet_id', $palet->id)->get();
            foreach ($oldImages as $oldImage) {
                Storage::disk('public')->delete('product-images/' . $oldImage->filename);
                $oldImage->delete();
            }

            $paletBrands = PaletBrand::where('palet_id', $palet->id)->get();
            foreach ($paletBrands as $paletBrand) {
                $paletBrand->delete();
            }
            $palet->delete();
            DB::commit();
            return new ResponseResource(true, "palet berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus palet: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus palet', 'error' => $e->getMessage()], 500);
        }
    }
}
