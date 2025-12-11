<?php

namespace App\Http\Controllers;

use App\Exports\ProductBkl;
use App\Http\Resources\ResponseResource;
use App\Models\Bkl;
use App\Models\FilterBkl;
use App\Models\New_product;
use App\Models\BklDocument;
use App\Models\BklItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

class BklController extends Controller
{

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $searchQuery = $request->input('q');
        $newProducts = Bkl::latest()
            ->where(function ($queryBuilder) use ($searchQuery) {
                $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });
        $totalPrice = $newProducts->sum('new_price_product');
        $newProducts = $newProducts->paginate(50);
        return new ResponseResource(true, "list product bkl", ['tota_price' => $totalPrice, 'products' => $newProducts]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {
            $product_filters = FilterBkl::where('user_id', $userId)->get();
            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }

            $insertData = $product_filters->map(function ($product) use ($userId) {
                return [
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
                    'created_at' => $product->created_at,
                    'updated_at' => now(),
                    'user_id' => $userId,
                    'type' => $product->type
                ];
            })->toArray();

            FilterBkl::where('user_id', $userId)->delete();
            Bkl::insert($insertData);

            logUserAction($request, $request->user(), "storage/expired_product/bkl", "Create bkl");

            DB::commit();
            return new ResponseResource(true, "Product BKL berhasil dibuat", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bkl', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(BKL $bkl)
    {
        return new ResponseResource(true, "list bkl", $bkl);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BKL $bkl) {}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BKL $bkl)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'old_barcode_product' => 'required',
            'new_barcode_product' => 'required',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump,sale,migrate',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',
            'new_discount',
            'display_price',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = $request->input('condition');
        $description = $request->input('deskripsi', '');

        $qualityData = [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null,
        ];

        $inputData = $request->only([
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_date_in_product',
            'new_status_product',
            'new_category_product',
            'new_tag_product',
            'new_discount',
            'display_price',
            'type'
        ]);

        $indonesiaTime = Carbon::now('Asia/Jakarta');
        $inputData['new_date_in_product'] = $indonesiaTime->toDateString();

        if ($status !== 'lolos') {

            $inputData['new_price_product'] = null;
            $inputData['new_category_product'] = null;
        }

        $inputData['new_quality'] = json_encode($qualityData);

        $bkl->update($inputData);

        return new ResponseResource(true, "Produk Berhasil di Update", $bkl);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BKL $bkl)
    {
        DB::beginTransaction();
        try {
            New_product::create([
                'code_document' => $bkl->code_document,
                'old_barcode_product' => $bkl->old_barcode_product,
                'new_barcode_product' => $bkl->new_barcode_product,
                'new_name_product' => $bkl->new_name_product,
                'new_quantity_product' => $bkl->new_quantity_product,
                'old_price_product' => $bkl->old_price_product,
                'new_price_product' => $bkl->new_price_product,
                'new_date_in_product' => $bkl->new_date_in_product,
                'new_status_product' => 'display',
                'new_quality' => $bkl->new_quality,
                'new_category_product' => $bkl->new_category_product,
                'new_tag_product' => $bkl->new_tag_product,
                'new_discount' => $bkl->new_discount,
                'display_price' => $bkl->display_price,
                'created_at' => $bkl->created_at,
                'updated_at' => $bkl->updated_at,
                'type' => $bkl->type,
            ]);

            $bkl->delete();
            DB::commit();

            return new ResponseResource(true, "Produk bundle berhasil dihapus", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function exportProduct(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $fileName = 'product-bkl.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);


            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductBkl($request), $publicPath . '/' . $fileName, 'public');


            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function listBklDocument(Request $request)
    {
        $query = BklDocument::query();

        if ($request->has('q')) {
            $search = $request->q;

            $query->where(function ($q) use ($search) {
                $q->where('code_document_bkl', 'like', '%' . $search . '%');
            });
        }
        $documents = $query->latest()->paginate(10);
        return new ResponseResource(true, "List BKL Documents", $documents);
    }

    public function storeBklDocument(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();
            $validator = Validator::make($request->all(), [
                'name_document' => 'required|string|unique:bkl_documents,code_document_bkl',
                'type' => 'required|in:in,out',
                'damage_qty' => 'nullable|integer|min:1',
                'colors' => 'nullable|array',
                'colors.*.color_tag_id' => 'required|exists:color_tags,id',
                'colors.*.qty' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => $validator->errors()], 422);
            }

            if (!$request->damage_qty && empty($request->colors)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Harus mengisi minimal Quantity Damage atau satu Warna.'
                ], 422);
            }

            $document = BklDocument::create([
                'code_document_bkl' => $request->name_document,
                'status' => 'done',
                'user_id' => $user->id
            ]);

            $this->saveItems($document->id, $request);

            DB::commit();
            return new ResponseResource(true, "BKL Berhasil Dibuat (Done)", $document->load('items'));
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
        }
    }

    public function detailBklDocument($id)
    {
        $document = BklDocument::with('items.colorTag')->find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
        }

        return new ResponseResource(true, "Detail BKL Document", $document);
    }

    public function toEdit($id)
    {
        $document = BklDocument::find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
        }

        if ($document->status === 'process') {
            return response()->json(['message' => 'Dokumen ini sudah dalam mode edit (Process)'], 400);
        }

        $document->update(['status' => 'process']);

        return new ResponseResource(true, "Mode Edit Aktif (Status: Process)", $document);
    }

    public function updateBklDocument(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $document = BklDocument::find($id);

            if (!$document) {
                return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
            }

            if ($document->status === 'done') {
                return response()->json(['message' => 'Dokumen terkunci (Done). Klik tombol Edit terlebih dahulu.'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name_document' => 'required|string|unique:bkl_documents,code_document_bkl,' . $id,
                'type' => 'required|in:in,out',
                'damage_qty' => 'nullable|integer|min:1',
                'colors' => 'nullable|array',
                'colors.*.color_tag_id' => 'required|exists:color_tags,id',
                'colors.*.qty' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $document->update([
                'code_document_bkl' => $request->name_document,
                'status' => 'done',
            ]);

            BklItem::where('bkl_document_id', $document->id)->delete();
            $this->saveItems($document->id, $request);

            DB::commit();
            return new ResponseResource(true, "BKL Berhasil Diupdate (Done)", $document->load('items'));
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal update', 'error' => $e->getMessage()], 500);
        }
    }

    private function saveItems($documentId, $request)
    {
        $type = $request->type;

        if ($request->has('damage_qty') && $request->damage_qty > 0) {
            BklItem::create([
                'bkl_document_id' => $documentId,
                'type' => $type,
                'qty' => $request->damage_qty,
                'color_tag_id' => null,
                'is_damaged' => true
            ]);
        }

        if ($request->has('colors') && is_array($request->colors)) {
            foreach ($request->colors as $colorItem) {
                BklItem::create([
                    'bkl_document_id' => $documentId,
                    'type' => $type,
                    'qty' => $colorItem['qty'],
                    'color_tag_id' => $colorItem['color_tag_id'],
                    'is_damaged' => null
                ]);
            }
        }
    }
}
