<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ColorRack;
use App\Models\Migrate;
use App\Models\MigrateDocument;
use App\Models\New_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MigrateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data['migrate'] = Migrate::where('status_migrate', 'proses')->latest()->paginate(20, ['*'], 'migrate_page');
        $data['code_document_migrate'] = $data['migrate']->isEmpty() ? codeDocumentMigrate() : $data['migrate'][0]['code_document_migrate'];

        $resource = new ResponseResource(true, "list migrate", $data);

        return $resource->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $userId = auth()->id();
            $codeDocumentMigrate = codeDocumentMigrate();

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'product_color' => 'nullable|string',
                'product_total' => 'required|numeric|min:1',
                'destiny_document_migrate' => 'nullable',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))
                    ->response()->setStatusCode(422);
            }

            $inputColor = $request->product_color ? ucfirst(strtolower(trim($request->product_color))) : null;

            $query = New_product::whereIn('new_status_product', ['display', 'expired', 'slow_moving']);
            if ($inputColor) {
                $query->where('new_tag_product', $inputColor);
            } else {
                $query->whereNull('new_tag_product');
            }

            $availableStock = $query->count();

            // Validasi Stok
            if ($availableStock == 0) {
                $colorName = $inputColor ?? 'Tanpa Warna/Rusak';
                return (new ResponseResource(false, "Stok produk warna '{$colorName}' kosong atau tidak ditemukan!", []))
                    ->response()->setStatusCode(422);
            }

            if ($request->product_total > $availableStock) {
                $colorName = $inputColor ?? 'Tanpa Warna/Rusak';
                return (new ResponseResource(false, "Stok warna '{$colorName}' tidak cukup! Diminta {$request->product_total}, tersedia {$availableStock} pcs.", []))
                    ->response()->setStatusCode(422);
            }

            // Proses dokumen migrasi
            $migrateDocument = MigrateDocument::where('user_id', $userId)
                ->where('status_document_migrate', 'proses')
                ->first();

            if ($migrateDocument == null) {
                $migrateDocumentStore = (new MigrateDocumentController)->store(new Request([
                    'code_document_migrate' => $codeDocumentMigrate,
                    'destiny_document_migrate' => $request->destiny_document_migrate,
                    'total_product_document_migrate' => 0,
                    'status_document_migrate' => 'proses',
                    'user_id' => $userId,
                ]));

                if ($migrateDocumentStore->getStatusCode() != 201) {
                    return $migrateDocumentStore;
                }
                $migrateDocument = $migrateDocumentStore->getData()->data->resource;
            }

            $migrate = Migrate::create([
                'code_document_migrate' => $migrateDocument->code_document_migrate,
                'product_color' => $inputColor,
                'product_total' => $request->product_total,
                'status_migrate' => 'proses',
                'user_id' => $userId
            ]);

            return (new ResponseResource(true, "Data berhasil ditambahkan!", $migrate))->response();
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Migrate $migrate = null)
    {
        if (is_null($migrate)) {
            $resource = new ResponseResource(true, "Data migrate", []);
        } else {
            $resource = new ResponseResource(true, "Data migrate", $migrate);
        }
        return $resource->response();
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Migrate $migrate)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Migrate $migrate)
    {
        DB::beginTransaction();
        try {
            $codeDocument = $migrate->code_document_migrate;

            $migrate->delete();

            $remainingItems = Migrate::where('code_document_migrate', $codeDocument)->count();

            if ($remainingItems == 0) {
                MigrateDocument::where('code_document_migrate', $codeDocument)->forceDelete();
            }

            DB::commit();

            return (new ResponseResource(true, "Data berhasil dihapus!", $migrate))->response();
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(false, "Data gagal dihapus!", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function addMigrate(New_product $newProduct)
    {
        $codeDocumentMigrate = codeDocumentMigrate();
        $statusProduct = $newProduct->new_status_product;
        if ($statusProduct == 'display' || $statusProduct == 'promo' || $statusProduct == 'bundle') {
            try {
                $migrateDocument = MigrateDocument::where('status_document_migrate', 'proses')->first();
                if ($migrateDocument == null) {
                    $migrateDocumentStore = (new MigrateDocumentController)->store(new Request([
                        'code_document_migrate' => $codeDocumentMigrate,
                        'destiny_document_migrate' => '-',
                        'total_product_document_migrate' => 0,
                        'total_price_document_migrate' => 0,
                        'status_document_migrate' => 'proses'
                    ]));

                    if ($migrateDocumentStore->getStatusCode() != 201) {
                        return $migrateDocumentStore;
                    }

                    $migrateDocument = $migrateDocumentStore->getData()->data->resource;
                }

                $migrate = Migrate::create([
                    'code_document_migrate' => $migrateDocument->code_document_migrate,
                    'old_barcode_product' => $newProduct['old_barcode_product'],
                    'new_barcode_product' => $newProduct['new_barcode_product'],
                    'new_name_product' => $newProduct['new_name_product'],
                    'new_qty_product' => $newProduct['new_quantity_product'],
                    'new_price_product' => $newProduct['new_price_product'],
                    'new_tag_product' => $newProduct['new_tag_product'],
                    'status_migrate' => 'proses',
                    'status_product_before' => $newProduct['new_status_product'],

                ]);

                $newProduct->update(['new_status_product' => 'sale']);
            } catch (\Exception $e) {
                $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
                return $resource->response()->setStatusCode(422);
            }

            $resource = new ResponseResource(true, "data berhasil disimpan!", $migrate);
            return $resource->response();
        } else {
            $resource = new ResponseResource(false, "Product tidak di temukan!", []);
            return $resource->response()->setStatusCode(404);
        }
    }


    public function displayMigrate(Request $request)
    {
        $userId = auth()->id();

        $migrateDocument = MigrateDocument::with([
            'migrates.colorRack.colorRackProducts.newProduct',
            'migrates.colorRack.colorRackProducts.bundle'
        ])->where([
            ['user_id', '=', $userId],
            ['status_document_migrate', '=', 'proses']
        ])->first();

        if (empty($migrateDocument)) {
            return new ResponseResource(true, "Data migrasi kosong", [
                "destionation" => "aktif",
                "data" => [
                    "total_display_qty" => 0,
                    "total_display_price" => 0,
                    "destiny_document_migrate" => null,
                    "destination_name" => null,
                    "migrates" => []
                ]
            ]);
        }

        $migrateDocument->migrates->transform(function ($item) {
            $rack = $item->colorRack;

            return [
                'id'                    => $item->id,
                'code_document_migrate' => $item->code_document_migrate,
                'color_rack_id'         => $item->color_rack_id,
                'status_migrate'        => $item->status_migrate,
                'rack_name'             => $rack ? $rack->name : 'Rak Migrate',
                'rack_barcode'          => $rack ? $rack->barcode : '-',
                'total_qty_in_rack'     => (int) $item->product_total,
                'total_new_price_rack'  => (float) ($rack ? $rack->total_new_price : 0),
            ];
        });

        $totalAllQty   = $migrateDocument->migrates->sum('total_qty_in_rack');
        $totalAllPrice = $migrateDocument->migrates->sum('total_new_price_rack');

        $migrateDocument->total_display_qty   = $totalAllQty;
        $migrateDocument->total_display_price = $totalAllPrice;

        $destinationName = null;
        if (is_numeric($migrateDocument->destiny_document_migrate)) {
            $destObj = \App\Models\Destination::find($migrateDocument->destiny_document_migrate);
            $destinationName = $destObj ? $destObj->shop_name : 'Toko Tidak Dikenal';
        }
        $migrateDocument->destination_name = $destinationName;

        return new ResponseResource(true, "Data dokumen aktif ditemukan", [
            "destionation" => "disable",
            "data" => $migrateDocument
        ]);
    }

    public function storeRack(Request $request)
    {
        try {
            $userId = auth()->id();
            $codeDocumentMigrate = codeDocumentMigrate();

            $validator = Validator::make($request->all(), [
                'barcode' => 'required|string|exists:color_racks,barcode',
                'destiny_document_migrate' => 'required',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
            }

            $rack = ColorRack::withCount('colorRackProducts')->where('barcode', $request->barcode)->first();

            if (!$rack) {
                return (new ResponseResource(false, "Rak dengan barcode tersebut tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            if ($rack->status !== 'proses' && $rack->status !== 'process') {
                return (new ResponseResource(false, "Rak belum berstatus proses!", []))->response()->setStatusCode(422);
            }

            $migrateDocument = MigrateDocument::where('user_id', $userId)
                ->where('status_document_migrate', 'proses')
                ->first();

            if (!$migrateDocument) {
                $migrateDocumentStore = (new MigrateDocumentController)->store(new Request([
                    'code_document_migrate'          => $codeDocumentMigrate,
                    'destiny_document_migrate'       => $request->destiny_document_migrate,
                    'total_product_document_migrate' => 0,
                    'status_document_migrate'        => 'proses',
                    'user_id'                        => $userId,
                ]));

                if ($migrateDocumentStore->getStatusCode() != 201) {
                    return $migrateDocumentStore;
                }
                $migrateDocument = $migrateDocumentStore->getData()->data->resource;
            }

            $isExists = Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
                ->where('color_rack_id', $rack->id)
                ->exists();

            if ($isExists) {
                return (new ResponseResource(false, "Rak ini sudah ada di dalam dokumen migrasi!", []))->response()->setStatusCode(409);
            }
            $migrate = Migrate::create([
                'code_document_migrate' => $migrateDocument->code_document_migrate,
                'color_rack_id'         => $rack->id,
                'product_total'         => $rack->color_rack_products_count,
                'status_migrate'        => 'proses',
                'user_id'               => $userId
            ]);

            return (new ResponseResource(true, "Rak berhasil ditambahkan ke dokumen!", $migrate))->response();
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function scanReturnMigrate(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string'
        ], [
            'barcode.required' => 'Barcode wajib diisi.'
        ]);

        DB::beginTransaction();
        try {
            $products = New_product::where('old_barcode_product', $request->barcode)
                ->orWhere('new_barcode_product', $request->barcode)
                ->get();

            if ($products->isEmpty()) {
                return (new ResponseResource(false, "Gagal! Barcode tidak dikenali di sistem.", null))
                    ->response()->setStatusCode(404);
            }

            $targetProduct = null;
            $errors = [];

            foreach ($products as $p) {

                $inPos = DB::table('color_rack_products')
                    ->join('color_racks', 'color_rack_products.color_rack_id', '=', 'color_racks.id')
                    ->where('color_rack_products.new_product_id', $p->id)
                    ->where('color_racks.status', 'migrate')
                    ->exists();

                if ($inPos) {
                    $errors['in_pos'] = true;
                    continue;
                }

                if ($p->new_status_product !== 'migrate') {
                    $errors['status'] = $p->new_status_product;
                    continue;
                }

                $quality = is_string($p->new_quality) ? json_decode($p->new_quality, true) : (array) $p->new_quality;
                if (!isset($quality['lolos']) || $quality['lolos'] !== 'lolos') {
                    $errors['quality'] = true;
                    continue;
                }

                if (is_null($p->new_tag_product) || !is_null($p->new_category_product)) {
                    $errors['type'] = true;
                    continue;
                }

                $targetProduct = $p;
                break;
            }

            if (!$targetProduct) {
                if (isset($errors['in_pos'])) {
                    return (new ResponseResource(false, "Gagal! Produk ini sudah masuk ke sistem POS. Gunakan fitur Dokumen BKL untuk meretur barang dari POS.", null))
                        ->response()->setStatusCode(422);
                }
                if (isset($errors['status'])) {
                    return (new ResponseResource(false, "Gagal! Tidak bisa di-scan karena produk ini sedang berstatus '{$errors['status']}'.", null))
                        ->response()->setStatusCode(422);
                }
                if (isset($errors['quality'])) {
                    return (new ResponseResource(false, "Gagal! Kualitas produk tidak memenuhi syarat (Damaged / Tidak Lolos).", null))
                        ->response()->setStatusCode(422);
                }
                if (isset($errors['type'])) {
                    return (new ResponseResource(false, "Gagal! Barcode ini bukan milik produk tipe Color.", null))
                        ->response()->setStatusCode(422);
                }

                return (new ResponseResource(false, "Gagal! Produk tidak memenuhi syarat untuk diproses.", null))
                    ->response()->setStatusCode(422);
            }

            // 4. LAKUKAN PROSES UPDATE
            $targetProduct->update([
                'new_status_product' => 'display'
            ]);

            $user = auth()->user();
            if ($user) {
                $scannedType = ($targetProduct->old_barcode_product === $request->barcode) ? 'Old Barcode' : 'New Barcode';
                $pesanLog = "Scan Retur Migrate: Mengembalikan produk {$targetProduct->new_barcode_product} ({$targetProduct->new_name_product}) ke display via {$scannedType} scan.";

                logUserAction($request, $user, 'Scan Return Migrate', $pesanLog);
            }

            DB::commit();

            return new ResponseResource(true, "Berhasil! Produk telah dikembalikan ke status display.", [
                'id'                  => $targetProduct->id,
                'old_barcode_product' => $targetProduct->old_barcode_product,
                'new_barcode_product' => $targetProduct->new_barcode_product,
                'new_name_product'    => $targetProduct->new_name_product,
                'new_status_product'  => $targetProduct->new_status_product,
                'new_tag_product'     => $targetProduct->new_tag_product
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Server Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
