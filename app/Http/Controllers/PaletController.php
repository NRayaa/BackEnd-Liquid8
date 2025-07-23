<?php

namespace App\Http\Controllers;

use Exception;
use ZipArchive;
use Carbon\Carbon;
use App\Models\Palet;
use App\Models\Category;
use App\Models\Warehouse;
use App\Models\PaletBrand;
use App\Models\PaletImage;
use App\Models\New_product;
use App\Models\PaletFilter;
use App\Models\Notification;
use App\Models\PaletProduct;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use App\Models\CategoryPalet;
use App\Models\ProductStatus;
use App\Models\StagingProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\Rule;
use App\Models\ProductCondition;
use App\Services\PaletSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Http\Resources\PaletResource;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\ResponseResource;
use App\Services\Bulky\ApiRequestService;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


class PaletController extends Controller
{
    protected $paletSyncService;


    public function __construct(PaletSyncService $paletSyncService)
    {
        $this->paletSyncService = $paletSyncService;
    }

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
            'old_price_product',
            'new_category_product',
            'new_status_product',
            'new_tag_product',
            'new_price_product',
            'created_at',
            'new_quality'
        ];

        $newProductsQuery = New_product::select($columns)
            ->whereIn('new_status_product', ['display', 'expired'])
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereNull('new_tag_product');

        $stagingProductsQuery = StagingProduct::select($columns)
            ->whereNotIn('new_status_product', ['dump', 'sale', 'migrate', 'repair'])
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
        $palets = Palet::with(['palet_sync_approves'])->latest()
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
        return new PaletResource(true, "list palet", $palets);
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

        $categoryPalets = ApiRequestService::get('products/filter/categories');
        $warehouses = ApiRequestService::get('products/filter/warehouse');
        $conditions = ApiRequestService::get('products/filter/conditions');
        $statuses = ApiRequestService::get('products/filter/statuses');
        $brands = ApiRequestService::get('products/filter/brands');

        $validCategoryPaletIds = collect($categoryPalets['data'])->pluck('id')->toArray();
        $validWarehouseIds = collect($warehouses['data'])->pluck('id')->toArray();
        $validConditionIds = collect($conditions['data'])->pluck('id')->toArray();
        $validStatuseIds = collect($statuses['data'])->pluck('id')->toArray();
        $validBrandIds = collect($brands['data'])->pluck('id')->toArray();

        try {
            // Validasi request
            $validator = Validator::make($request->all(), [
                'images' => 'array|nullable',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:5120',
                'name_palet' => 'required|string',
                'total_price_palet' => 'required|numeric',
                // 'total_product_palet' => 'required|integer',
                'file_pdf' => 'nullable|mimes:pdf',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'is_sale' => 'boolean',
                'category_palet_id' => ['required', Rule::in($validCategoryPaletIds)],
                'product_brand_ids' => 'array|nullable',
                'product_brand_ids.*' => [Rule::in($validBrandIds)],
                'warehouse_id' => ['required', Rule::in($validWarehouseIds)],
                'product_condition_id' => ['required', Rule::in($validConditionIds)],
                'product_status_id' => ['required', Rule::in($validStatuseIds)],
                'discount' => 'nullable'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $validatedData = [];

            // if ($request->hasFile('file_pdf')) {
            //     $file = $request->file('file_pdf');
            //     $filename = time() . '_' . $file->getClientOriginalName();
            //     $pdfPath = $file->storeAs('palets_pdfs', $filename, 'public');
            //     $validatedData['file_pdf'] = asset('storage/' . $pdfPath);
            // } else {
            //     $validatedData['file_pdf'] = null;
            // }

            $product_filters = PaletFilter::where('user_id', $userId)->get();

            $categoryPaletName = collect($categoryPalets['data'])
                ->firstWhere('id', $request['category_palet_id'])['name'] ?? null;
            $warehouseName = collect($warehouses['data'])
                ->firstWhere('id', $request['warehouse_id'])['name'] ?? null;
            $conditionName = collect($conditions['data'])
                ->firstWhere('id', $request['product_condition_id'])['title'] ?? null;
            $statusName = collect($statuses['data'])
                ->firstWhere('id', $request['product_status_id'])['status'] ?? null;
            $brandNames = collect($brands['data'])
                ->whereIn('id', $request['product_brand_ids'])
                ->pluck('name')
                ->values()
                ->all();

            // Create Palet
            $palet = Palet::create([
                'name_palet' => $request['name_palet'],
                'category_palet' => $categoryPaletName,
                'total_price_palet' => $request['total_price_palet'],
                'total_product_palet' => $product_filters->count(),
                'palet_barcode' => barcodePalet($userId),
                'file_pdf' => $validatedData['file_pdf'] ?? null,
                'description' => $request['description'] ?? null,
                'is_active' => $request['is_active'] ?? false,
                'warehouse_name' => $warehouseName,
                'product_condition_name' => $conditionName,
                'product_status_name' => $statusName,
                'is_sale' => $request['is_sale'] ?? false,
                // 'category_id' => $request['category_id'],
                'category_palet_id' => $request['category_palet_id'],
                'warehouse_id' => $request['warehouse_id'],
                'product_condition_id' => $request['product_condition_id'],
                'product_status_id' => $request['product_status_id'],
                'discount' => $request['discount'],
                'brand_ids' => $request['product_brand_ids'],
                'brand_names' => $brandNames,
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

            $insertData = $product_filters->map(function ($product) use ($palet) {
                return [
                    'palet_id' => $palet->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => round($product->old_price_product - $product->old_price_product * ($palet->discount / 100), 2),
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => $product->new_status_product,
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $palet->discount,
                    'display_price' => $product->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            PaletProduct::insert($insertData);

            PaletFilter::where('user_id', $userId)->delete();

            $palet->load('paletProducts');

            $disk = Storage::disk('public');
            $folder = 'palets_pdfs';
            $filename = time() . '_' . $palet->palet_barcode . '.pdf';
            $path = "$folder/$filename";

            $disk->makeDirectory($folder);

            $pdf = Pdf::loadView('pdf.palet', ['palet' => $palet])
                ->setPaper('a4', 'landscape');

            $disk->put($path, $pdf->output());

            $palet->update(['file_pdf' => $path]);
            DB::commit();

            return new ResponseResource(true, "Data palet berhasil ditambahkan", $palet);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store palet: ' . $e->getMessage());
            return (new ResponseResource(false, "Data gagal ditambahkan", null))->response()->setStatusCode(500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Palet $palet)
    {
        $query = $request->input('q');

        $palet->load([
            'paletImages',
            'paletProducts' => function ($productPalet) use ($query) {
                $productPalet->where(function ($q) use ($query) {
                    if (!empty($query)) {
                        $q->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    }
                })
                    ->where(function ($q) {
                        $q->where('is_bulky', '!=', 'yes')
                            ->orWhereNull('is_bulky');
                    });
            }
        ]);

        if ($palet->discount === null) {
            $palet->discount = 0;
        }

        $palet->total_harga_lama = $palet->paletProducts->sum('old_price_product');


        // untuk ubah response saja supaya jadi lebih mudah FE nya bang
        $brands = [];
        foreach (($palet->brand_ids ?? []) as $index => $brandId) {
            $brands[] = [
                'id' => $brandId,
                'brand_name' => $palet->brand_names[$index] ?? null,
            ];
        }

        $palet->brands = $brands;
        unset($palet->brand_ids, $palet->brand_names);

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

        $categoryPalets = ApiRequestService::get('products/filter/categories');
        $warehouses = ApiRequestService::get('products/filter/warehouse');
        $conditions = ApiRequestService::get('products/filter/conditions');
        $statuses = ApiRequestService::get('products/filter/statuses');
        $brands = ApiRequestService::get('products/filter/brands');

        $validCategoryPaletIds = collect($categoryPalets['data'])->pluck('id')->toArray();
        $validWarehouseIds = collect($warehouses['data'])->pluck('id')->toArray();
        $validConditionIds = collect($conditions['data'])->pluck('id')->toArray();
        $validStatuseIds = collect($statuses['data'])->pluck('id')->toArray();
        $validBrandIds = collect($brands['data'])->pluck('id')->toArray();

        try {
            // Validasi request
            $validator = Validator::make($request->all(), [
                'images' => 'array|nullable',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:5120',
                'name_palet' => 'required|string',
                'total_price_palet' => 'required|numeric',
                'total_product_palet' => 'required|integer',
                'palet_barcode' => 'required|string|unique:palets,palet_barcode,' . $palet->id,
                'file_pdf' => 'nullable|mimes:pdf',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'is_sale' => 'boolean',
                'category_palet_id' => ['required', Rule::in($validCategoryPaletIds)],
                'product_brand_ids' => 'array|nullable',
                'product_brand_ids.*' => [Rule::in($validBrandIds)],
                'warehouse_id' => ['required', Rule::in($validWarehouseIds)],
                'product_condition_id' => ['required', Rule::in($validConditionIds)],
                'product_status_id' => ['required', Rule::in($validStatuseIds)],
                'discount' => 'nullable'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->hasFile('file_pdf')) {
                // Hapus file lama jika ada dan eksis
                $oldPath = $palet->getRawOriginal('file_pdf'); // hindari konflik accessor
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }

                // ambil barcode sebagai bagain dari nama file
                $barcode = $request->input('palet_barcode', $palet->palet_barcode);
                $sanitizedBarcode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $barcode);

                // Buat nama file baru
                $file = $request->file('file_pdf');
                $folder = 'palets_pdfs';
                $filename = time() . '_' . $sanitizedBarcode . '.pdf';
                $path = "$folder/$filename";

                // Simpan file ke disk public
                Storage::disk('public')->putFileAs($folder, $file, $filename);

                $validatedData['file_pdf'] = $path;
            } else {
                // Pertahankan path lama
                $validatedData['file_pdf'] = $palet->getRawOriginal('file_pdf');
            }

            $categoryPaletName = collect($categoryPalets['data'])
                ->firstWhere('id', $request['category_palet_id'])['name'] ?? null;
            $warehouseName = collect($warehouses['data'])
                ->firstWhere('id', $request['warehouse_id'])['name'] ?? null;
            $conditionName = collect($conditions['data'])
                ->firstWhere('id', $request['product_condition_id'])['title'] ?? null;
            $statusName = collect($statuses['data'])
                ->firstWhere('id', $request['product_status_id'])['status'] ?? null;
            $brandNames = collect($brands['data'])
                ->whereIn('id', $request['product_brand_ids'])
                ->pluck('name')
                ->values()
                ->all();

            $palet->update([
                'name_palet' => $request['name_palet'],
                'category_palet' => $categoryPaletName,
                'total_price_palet' => $request['total_price_palet'],
                'total_price_palet' => $request['total_price_palet'],
                'total_product_palet' => $request['total_product_palet'],
                'palet_barcode' => $request['palet_barcode'],
                'file_pdf' => $validatedData['file_pdf'] ?? null,
                'description' => $request['description'] ?? null,
                'is_active' => $request['is_active'] ?? false,
                'warehouse_name' => $warehouseName,
                'product_condition_name' => $conditionName,
                'product_status_name' => $statusName,
                'is_sale' => $request['is_sale'] ?? false,
                // 'category_id' => $request['category_id'],
                'category_palet_id' => $request['category_palet_id'],
                'warehouse_id' => $request['warehouse_id'],
                'product_condition_id' => $request['product_condition_id'],
                'product_status_id' => $request['product_status_id'],
                'discount' => $request['discount'],
                'brand_ids' => $request['product_brand_ids'],
                'brand_names' => $brandNames,
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

            DB::commit();

            return new ResponseResource(true, "Data palet berhasil diperbarui", $palet->load(['paletImages']));
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

            $oldPath = $palet->getRawOriginal('file_pdf');
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
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

            // Menulis data dari $palet langsung ke kolom-kolom tanpa menggunakan header variabel
            $sheet->setCellValue('A1', 'Nama Palet');
            $sheet->setCellValue('B1', 'Harga Lama');
            $sheet->setCellValue('C1', 'Qty');
            $sheet->setCellValue('D1', 'Diskon (%)');
            $sheet->setCellValue('E1', 'Harga Baru');
            $sheet->setCellValue('F1', 'Palet Barcode');

            // Mengisi data dari objek $palet
            $sheet->setCellValue('A2', $palet->name_palet);
            $sheet->setCellValue('B2', $palet->total_product_palet);
            $sheet->setCellValue('C2', number_format($palet->paletProducts->sum('old_price_product'), 2, ',', '.'));
            $sheet->setCellValue('D2', number_format($palet->discount, 0));
            $sheet->setCellValue('E2', number_format($palet->total_price_palet, 2, ',', '.'));
            $sheet->setCellValue('F2', $palet->palet_barcode);

            $rowIndex = 4;
            $sheet->setCellValue('A' . $rowIndex, 'Name Product');
            $sheet->setCellValue('B' . $rowIndex, 'Qty');
            $sheet->setCellValue('C' . $rowIndex, 'Harga Lama ');
            $sheet->setCellValue('D' . $rowIndex, 'Discount');
            $sheet->setCellValue('E' . $rowIndex, 'Harga Baru');
            $sheet->setCellValue('F' . $rowIndex, 'Barcode');

            // Mengisi data produk palet
            $rowIndex++; // Pindah ke baris berikutnya untuk data produk
            foreach ($palet->paletProducts as $product) {
                $sheet->setCellValue('A' . $rowIndex, $product->new_name_product);
                $sheet->setCellValue('B' . $rowIndex, $product->new_quantity_product);
                $sheet->setCellValue('C' . $rowIndex, number_format($product->old_price_product, 2, ',', '.'));
                $sheet->setCellValue('D' . $rowIndex, number_format($product->new_discount, 0));
                $sheet->setCellValue('E' . $rowIndex, number_format($product->new_price_product, 2, ',', '.'));
                $sheet->setCellValue('F' . $rowIndex, $product->new_barcode_product);
                $rowIndex++;
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
        $categories = CategoryPalet::select(
            'id',
            'name_category_palet AS name_category',
            'discount_category_palet AS discount_category',
            'max_price_category_palet AS max_price_category',
            'created_at',
            'updated_at'
        )->latest()->get();

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

    public function syncPalet()
    {
        $palets = $this->paletSyncService->syncPalet();
        if ($palets) {
            return new ResponseResource(true, "Palet berhasil disinkronisasi", $palets);
        }

        return new ResponseResource(false, "Gagal menyinkronkan palet", null);
    }


    public function listFilterToBulky(Request $request)
    {
        $userId = auth()->id();
        $search = $request->query('q');

        // Ambil palets dengan is_bulky == 'waiting_list' dan user_id
        $paletsQuery = Palet::where('is_bulky', 'waiting_list')->where('user_id', $userId);


        // Terapkan filter pencarian jika ada
        if ($search) {
            $paletsQuery->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'like', "%$search%")
                    ->orWhere('old_barcode_product', 'like', "%$search%")
                    ->orWhere('new_name_product', 'like', "%$search%");
            });
        }

        // Dapatkan palets dan paginate
        $palets = $paletsQuery->paginate(33);

        // Hitung total harga produk
        $priceOldProduct = PaletProduct::whereIn('palet_id', $palets->pluck('id'))->sum('old_price_product');

        // Kembalikan response
        return new ResponseResource(true, "Produk palet ditemukan", [
            'oldPrice' => $priceOldProduct,
            'data' => $palets
        ]);
    }

    public function addFilterBulky(Request $request, $paletId)
    {
        try {
            $palet = Palet::where('id', $paletId)->where('is_active', 1)->whereNull('is_bulky')->firstOrFail();

            if ($palet->is_bulky === 'waiting_list') {
                return (new ResponseResource(false, "Palet sudah dalam waiting list bulky", null))
                    ->response()
                    ->setStatusCode(400);
            }

            if ($palet->is_bulky === 'waiting_approve') {
                return (new ResponseResource(false, "Palet sudah dalam proses persetujuan bulky", null))
                    ->response()
                    ->setStatusCode(400);
            }

            $palet->update(['is_bulky' => 'waiting_list', 'user_id' => auth()->id()]);

            return new ResponseResource(true, "Produk palet berhasil diubah menjadi bulky", $palet);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return (new ResponseResource(false, "Palet tidak ditemukan atau harus active", null))
                ->response()
                ->setStatusCode(404);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function toUnFilterBulky(Request $request, $paletId)
    {
        $productPalet = Palet::where('is_bulky', 'waiting_list')->findOrFail($paletId);
        if (!$productPalet) {
            return (new ResponseResource(false, "palet tidak ditemukan", null))->response()->setStatusCode(404);
        }
        $productPalet->update([
            'is_bulky' => null,
            'user_id' => null
        ]);
        return new ResponseResource(true, "Produk palet berhasil diubah menjadi tidak bulky", $productPalet);
    }

    public function updateToApprove(Request $request)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();

            // Ambil jumlah palets yang sesuai dengan kondisi
            $paletCount = Palet::where('is_bulky', 'waiting_list')->where('user_id', $userId)->update(['is_bulky' => 'waiting_approve']);
            // Pastikan ada palets yang ditemukan dan diperbarui
            if ($paletCount === 0) {
                return response()->json(['message' => 'Tidak ada palet untuk diperbarui.'], 404);
            }

            // Buat notifikasi
            $notification = Notification::create([
                'user_id' => $userId,
                'notification_name' => 'palet waiting approve to bulky',
                'status' => 'palet',
                'role' => 'Spv',
                'riwayat_check_id' => null,
                'read_at' => null,
                'created_at' => Carbon::now('Asia/Jakarta'),
                'updated_at' => Carbon::now('Asia/Jakarta'),
                'external_id' => null,
                'approved' => '1'
            ]);

            DB::commit();

            return response()->json(['message' => 'Palet berhasil diperbarui menjadi waiting approve.', 'notification' => $notification], 200);
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json(['message' => 'Terjadi kesalahan saat memperbarui palet.', 'error' => $e->getMessage()], 500);
        }
    }

    public function bulkyFilterApprove(Request $request, $userId)
    {
        // Mendapatkan nilai query 'q' dari request
        $search = $request->query('q');

        // Membangun query untuk mendapatkan palet
        $paletsQuery = Palet::where('user_id', $userId)->where('is_bulky', 'waiting_approve');

        if ($search) {
            $paletsQuery->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'like', "%$search%")
                    ->orWhere('old_barcode_product', 'like', "%$search%")
                    ->orWhere('new_name_product', 'like', "%$search%");
            });
        }

        // Mendapatkan palet dengan paginasi
        $palets = $paletsQuery->paginate(33);

        // Jika ada palet, hitung total harga
        $totalPrice = $palets->isNotEmpty() ? $palets->getCollection()->sum('total_price_palet') : 0;

        // Kembalikan respons
        return new ResponseResource(true, "Produk palet ditemukan", [
            'price' => $totalPrice,
            'data' => $palets
        ]);
    }

    public function approveSyncPalet(Request $request)
    {
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        DB::beginTransaction();

        try {
            $palets = Palet::with(['paletImages'])
                ->where('user_id', $request->input('user_id'))
                ->where('is_bulky', 'waiting_approve')
                ->get();

            $products = [];



            foreach ($palets as $palet) {
                // Mendapatkan gambar dalam bentuk array
                $images = $palet->paletImages()->pluck('filename')->toArray(); // Ambil nama file gambar
                // Ambil harga lama (old prices)
                $oldPrices = PaletProduct::where('palet_id', $palet->id)->pluck('old_price_product');

                // Ambil harga sebelum diskon pertama jika ada, jika tidak, gunakan 0
                $priceBeforeDiscount = $oldPrices->isNotEmpty() ? $oldPrices->first() : 0;

                // Ambil brand_ids
                $brandIds = $palet->brand_ids; // Asumsikan ini adalah array

                $products[] = [
                    'name' => $palet->name_palet,
                    'price' => $palet->total_price_palet,
                    'price_before_discount' => (float)$priceBeforeDiscount,
                    'total_quantity' => $palet->total_product_palet,
                    'description' => $palet->description ?? 'Deskripsi tidak ada',
                    'product_category_id' => $palet->product_category_id ?? null,
                    'product_condition_id' => $palet->product_condition_id, // Ambil ID langsung
                    'product_status_id' => $palet->product_status_id, // Ambil ID langsung
                    // 'is_active' => true,
                    'images' => [], // Gambar akan diisi kemudian
                    'brand_ids' => $brandIds, // Pastikan ini array
                ];

                // Menambahkan gambar ke produk
                foreach ($images as $image) {
                    $filePath = public_path('path/to/images/' . $image); // Sesuaikan path sesuai struktur file kamu
                    if (file_exists($filePath)) {
                        // Jika file ada, tambahkan ke array gambar
                        $products[count($products) - 1]['images'][] = new \Illuminate\Http\UploadedFile($filePath, $image);
                    }
                }
                $palet->update(['is_bulky' => 'done']);
            }

            // Kemudian kirim data ke API
            $productBulky = ApiRequestService::post('/products/create-batch', [
                'products' => $products,
            ]);

            $notification = Notification::where('user_id', $request->input('user_id'))->where('status', 'palet')->where('approved', '1')->first();
            $notification->update([
                'approved' => '2'
            ]);


            DB::commit();

            return new ResponseResource(true, "berhasil mensinkronkan", null);

            // Log tindakan pengguna
            logUserAction($request, $request->user(), "notif/palet/approve", "Menekan tombol approve");
        } catch (\Exception $e) {
            DB::rollBack();
            throw new Exception("Terjadi kesalahan: " . $e->getMessage());
        }
    }

    public function rejectSyncPalet(Request $request)
    {
        try {
            $updatePalets = Palet::where('is_bulky', 'waiting_approve')->where('user_id', $request->input('user_id'))->update(['is_bulky' => null, 'user_id' => null]);

            if ($updatePalets) {
                return new ResponseResource(true, "Berhasil di sync", null);
            } else {
                return (new ResponseResource(false, "Gagal me reject palet", null));
            }

            $notification = Notification::where('user_id', $request->input('user_id'))->where('status', 'palet')->where('approved', '1')->first();
            $notification->update([
                'approved' => '1'
            ]);
        } catch (\Exception $e) {
            // Log error ke sistem
            \Log::error("Error rejecting palets for user " . $request->input('user_id')  . $e->getMessage());

            return (new ResponseResource(false, "Terjadi kesalahan saat menolak sinkronisasi palet", null))->response()->setStatusCode(500);
        }
    }
}
