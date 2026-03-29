<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ColorRack;
use App\Models\ColorRackProduct;
use App\Models\New_product;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ColorRackController extends Controller
{

    public function index(Request $request)
    {
        $query = ColorRack::with('colorRackProducts.newProduct')
            ->withCount('colorRackProducts')
            ->where('status', 'display');

        if ($request->has('q') && $request->q != '') {
            $q = $request->q;

            $query->where(function ($queryBuilder) use ($q) {
                $queryBuilder->where('name', 'LIKE', "%{$q}%")
                    ->orWhere('barcode', 'LIKE', "%{$q}%")
                    ->orWhereHas('colorRackProducts.newProduct', function ($subQ) use ($q) {
                        $subQ->where('new_barcode_product', 'LIKE', "%{$q}%")
                            ->orWhere('old_barcode_product', 'LIKE', "%{$q}%")
                            ->orWhere('new_name_product', 'LIKE', "%{$q}%");
                    });
            });
        }

        $racks = $query->latest()->paginate(10);

        $racks->getCollection()->transform(function ($rack) {
            return [
                "id"              => $rack->id,
                "name"            => $rack->name,
                "barcode"         => $rack->barcode,
                "status"          => $rack->status,
                "created_at"      => $rack->created_at,
                "updated_at"      => $rack->updated_at,
                "total_items"     => $rack->color_rack_products_count,
                "total_old_price" => $rack->total_old_price,
                "total_new_price" => $rack->total_new_price,
            ];
        });


        $totalRacks = ColorRack::count();
        $totalProductsInRacks = ColorRackProduct::count();

        return (new ResponseResource(true, 'List Data Color Rack', [
            'racks'                   => $racks,
            'total_racks'             => $totalRacks,
            'total_products_in_racks' => $totalProductsInRacks
        ]))->response()->setStatusCode(200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string'
        ]);

        $inputName = trim($request->name);

        $baseName = preg_replace('/\s+\d+$/', '', $inputName);

        $name = $inputName;
        $counter = 1;

        if (preg_match('/\s+(\d+)$/', $inputName, $matches)) {

            $counter = (int)$matches[1];
            $name = $baseName . ' ' . $counter;
        } else {


            if (ColorRack::where('name', $name)->exists()) {
                $name = $baseName . ' ' . $counter;
            }
        }

        while (ColorRack::where('name', $name)->exists()) {
            $counter++;
            $name = $baseName . ' ' . $counter;
        }

        do {
            $barcode = strtoupper(\Illuminate\Support\Str::random(6));
        } while (ColorRack::where('barcode', $barcode)->exists());

        $rack = ColorRack::create([
            'name'    => $name,
            'barcode' => $barcode,
            'status'  => 'display'
        ]);

        return (new ResponseResource(true, 'Color Rack berhasil dibuat', $rack))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, $id)
    {
        $rack = ColorRack::find($id);

        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))
                ->response()->setStatusCode(404);
        }

        $productQuery = ColorRackProduct::with(['newProduct', 'bundle'])->where('color_rack_id', $rack->id);

        if ($request->has('q') && $request->q != '') {
            $q = $request->q;

            $productQuery->where(function ($mainQ) use ($q) {
                $mainQ->whereHas('newProduct', function ($subQ) use ($q) {
                    $subQ->where('new_name_product', 'LIKE', "%{$q}%")
                        ->orWhere('new_barcode_product', 'LIKE', "%{$q}%")
                        ->orWhere('old_barcode_product', 'LIKE', "%{$q}%");
                })
                    ->orWhereHas('bundle', function ($subQ) use ($q) {
                        $subQ->where('name_bundle', 'LIKE', "%{$q}%")
                            ->orWhere('barcode_bundle', 'LIKE', "%{$q}%");
                    });
            });
        }

        $paginatedProducts = $productQuery->latest()->paginate(50);

        $paginatedProducts->getCollection()->transform(function ($item) {
            if ($item->bundle_id && $item->bundle) {
                return [
                    'id'                   => $item->id,
                    'item_id'              => $item->bundle->id,
                    'is_bundle'            => true,
                    'new_name_product'     => "[BUNDLE] " . $item->bundle->name_bundle,
                    'new_barcode_product'  => $item->bundle->barcode_bundle,
                    'old_barcode_product'  => null,
                    'new_tag_product'      => $item->bundle->name_color,
                    'new_status_product'   => $item->bundle->product_status,
                    'new_price_product'    => $item->bundle->total_price_custom_bundle,
                    'old_price_product'    => $item->bundle->total_price_bundle,
                    'added_at'             => $item->created_at,
                ];
            } else if ($item->newProduct) {
                return [
                    'id'                   => $item->id,
                    'item_id'              => $item->newProduct->id,
                    'is_bundle'            => false,
                    'new_name_product'     => $item->newProduct->new_name_product,
                    'new_barcode_product'  => $item->newProduct->new_barcode_product,
                    'old_barcode_product'  => $item->newProduct->old_barcode_product,
                    'new_tag_product'      => $item->newProduct->new_tag_product,
                    'new_status_product'   => $item->newProduct->new_status_product,
                    'new_price_product'    => $item->newProduct->new_price_product ?? $item->newProduct->new_price_eq,
                    'old_price_product'    => $item->newProduct->old_price_product ?? $item->newProduct->old_price_eq,
                    'added_at'             => $item->created_at,
                ];
            }
            return null;
        });

        $rack->load(['colorRackProducts.newProduct', 'colorRackProducts.bundle']);

        $rackInfo = [
            'id'              => $rack->id,
            'name'            => $rack->name,
            'barcode'         => $rack->barcode,
            'status'          => $rack->status,
            'total_items'     => $rack->colorRackProducts->count(),
            'total_old_price' => $rack->total_old_price,
            'total_new_price' => $rack->total_new_price,
        ];

        return (new ResponseResource(true, 'Detail Data Color Rack dan Produk/Bundle', [
            'rack_info' => $rackInfo,
            'products'  => $paginatedProducts
        ]))->response()->setStatusCode(200);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string'
        ]);

        $rack = ColorRack::find($id);

        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))
                ->response()->setStatusCode(404);
        }

        $inputName = trim($request->name);

        $baseName = preg_replace('/\s+\d+$/', '', $inputName);
        $name = $inputName;
        $counter = 1;

        if ($rack->name !== $inputName) {

            if (preg_match('/\s+(\d+)$/', $inputName, $matches)) {
                $counter = (int)$matches[1];
                $name = $baseName . ' ' . $counter;
            } else {

                if (ColorRack::where('name', $name)->where('id', '!=', $id)->exists()) {
                    $name = $baseName . ' ' . $counter;
                }
            }

            while (ColorRack::where('name', $name)->where('id', '!=', $id)->exists()) {
                $counter++;
                $name = $baseName . ' ' . $counter;
            }
        }

        $rack->update(['name' => $name]);

        return (new ResponseResource(true, 'Nama Color Rack berhasil diupdate', $rack))
            ->response()->setStatusCode(200);
    }

    public function toMigrate($id)
    {
        $rack = ColorRack::find($id);

        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))
                ->response()->setStatusCode(404);
        }

        if ($rack->status === 'process') {
            return (new ResponseResource(false, 'Status rack sudah migrate', null))
                ->response()->setStatusCode(400);
        }

        if ($rack->status === 'migrate') {
            return (new ResponseResource(false, 'Status rack sudah migrate', null))
                ->response()->setStatusCode(400);
        }

        $rack->update(['status' => 'process']);

        return (new ResponseResource(true, 'Status berhasil diubah ke migrate', $rack))
            ->response()->setStatusCode(200);
    }

    public function addProduct(Request $request, $id)
    {
        $request->validate(['barcode' => 'required|string']);

        $rack = ColorRack::find($id);
        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))->response()->setStatusCode(404);
        }

        if ($rack->status === 'process') {
            return (new ResponseResource(false, 'Tidak bisa menambah produk ke rak yang sudah migrate', null))->response()->setStatusCode(400);
        }

        if ($rack->status === 'migrate') {
            return (new ResponseResource(false, 'Tidak bisa menambah produk ke rak yang sudah migrate', null))->response()->setStatusCode(400);
        }

        $barcode = $request->barcode;


        $product = New_product::where('new_barcode_product', $barcode)->first();
        $bundle  = null;

        if (!$product) {
            $bundle = \App\Models\Bundle::where('barcode_bundle', $barcode)->first();
        }

        if (!$product && !$bundle) {
            return (new ResponseResource(false, 'Produk atau Bundle dengan barcode tersebut tidak ditemukan', null))->response()->setStatusCode(404);
        }

        DB::beginTransaction();
        try {
            $responseData = null;

            if ($bundle) {

                if (!is_null($bundle->category)) {
                    return (new ResponseResource(false, 'Gagal: Kategori bundle bukan color', null))->response()->setStatusCode(422);
                }

                if ($bundle->product_status === 'sale') {
                    return (new ResponseResource(false, 'Gagal: Status bundle sudah terjual (sale)', null))->response()->setStatusCode(422);
                }

                if (ColorRackProduct::where('bundle_id', $bundle->id)->exists()) {
                    return (new ResponseResource(false, 'Bundle ini sudah berada di dalam rak color', null))->response()->setStatusCode(409);
                }

                $rackProduct = ColorRackProduct::create([
                    'color_rack_id'  => $rack->id,
                    'new_product_id' => null,
                    'bundle_id'      => $bundle->id,
                ]);

                $rackProduct->load('bundle');
                $responseData = $rackProduct->bundle;
            } else {

                $allowedStatuses = ['display', 'expired', 'slow_moving'];
                if (!in_array($product->new_status_product, $allowedStatuses)) {
                    return (new ResponseResource(false, 'Gagal: Status produk harus display, expired, atau slow_moving', null))->response()->setStatusCode(422);
                }

                $quality = is_string($product->new_quality) ? json_decode($product->new_quality, true) : $product->new_quality;
                if (is_string($quality)) $quality = json_decode($quality, true);

                if (empty($quality['lolos']) || $quality['lolos'] !== 'lolos') {
                    return (new ResponseResource(false, 'Gagal: Kualitas produk tidak memenuhi syarat (Bukan Lolos)', null))->response()->setStatusCode(422);
                }

                if (is_null($product->new_tag_product) || !is_null($product->new_category_product)) {
                    return (new ResponseResource(false, 'Gagal: Bukan Produk Color (Tag harus ada, Kategori harus NULL)', null))->response()->setStatusCode(422);
                }

                if (ColorRackProduct::where('new_product_id', $product->id)->exists()) {
                    return (new ResponseResource(false, 'Produk ini sudah berada di dalam rak color', null))->response()->setStatusCode(409);
                }

                $rackProduct = ColorRackProduct::create([
                    'color_rack_id'  => $rack->id,
                    'new_product_id' => $product->id,
                    'bundle_id'      => null,
                ]);

                $rackProduct->load('newProduct');
                $responseData = $rackProduct->newProduct;
            }

            DB::commit();
            return (new ResponseResource(true, 'Item berhasil ditambahkan ke Color Rack', $responseData))->response()->setStatusCode(201);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, 'Terjadi kesalahan sistem: ' . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function removeProduct(Request $request, $id)
    {
        $request->validate(['barcode' => 'required|string']);

        $rack = ColorRack::find($id);
        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))->response()->setStatusCode(404);
        }

        if ($rack->status === 'process') {
            return (new ResponseResource(false, 'Tidak bisa mengeluarkan produk dari rak yang migrate', null))->response()->setStatusCode(400);
        }

        if ($rack->status === 'migrate') {
            return (new ResponseResource(false, 'Tidak bisa mengeluarkan produk dari rak yang migrate', null))->response()->setStatusCode(400);
        }

        $barcode = $request->barcode;
        $product = New_product::where('new_barcode_product', $barcode)->first();
        $bundle  = null;

        if (!$product) {
            $bundle = \App\Models\Bundle::where('barcode_bundle', $barcode)->first();
        }

        if (!$product && !$bundle) {
            return (new ResponseResource(false, 'Produk/Bundle tidak ditemukan', null))->response()->setStatusCode(404);
        }

        $query = ColorRackProduct::with(['newProduct', 'bundle'])->where('color_rack_id', $rack->id);

        if ($bundle) {
            $query->where('bundle_id', $bundle->id);
        } else {
            $query->where('new_product_id', $product->id);
        }

        $rackProduct = $query->first();

        if (!$rackProduct) {
            return (new ResponseResource(false, 'Item tidak ditemukan di dalam rak ini', null))->response()->setStatusCode(404);
        }

        DB::beginTransaction();
        try {

            $itemDetail = $bundle ? $rackProduct->bundle : $rackProduct->newProduct;

            $rackProduct->delete();
            DB::commit();

            return (new ResponseResource(true, 'Item berhasil dikeluarkan dari Color Rack', $itemDetail))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, 'Terjadi kesalahan sistem: ' . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function listColorRackProducts(Request $request)
    {
        try {
            $displayQuery = New_product::query()
                ->select(
                    'id',
                    'new_name_product',
                    'new_barcode_product',
                    'old_barcode_product',
                    'new_status_product',
                    'new_tag_product',
                    'new_price_product',
                    'old_price_product',
                    'created_at',
                    DB::raw("0 as is_bundle")
                )
                ->whereNull('rack_id')
                ->whereNotIn('id', function ($q) {
                    $q->select('new_product_id')
                        ->from('color_rack_products')
                        ->whereNotNull('new_product_id');
                })
                ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->where(function ($query) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
                });

            $bundleQuery = \App\Models\Bundle::query()
                ->select(
                    'id',
                    DB::raw("CONCAT('[BUNDLE] ', name_bundle) as new_name_product"),
                    'barcode_bundle as new_barcode_product',
                    DB::raw("NULL as old_barcode_product"),
                    'product_status as new_status_product',
                    'name_color as new_tag_product',
                    'total_price_custom_bundle as new_price_product',
                    'total_price_bundle as old_price_product',
                    'created_at',
                    DB::raw("1 as is_bundle")
                )
                ->whereNull('rack_id')
                ->whereNotIn('id', function ($q) {
                    $q->select('bundle_id')
                        ->from('color_rack_products')
                        ->whereNotNull('bundle_id');
                })
                ->whereNotNull('name_color')
                ->whereNull('category')
                ->where('product_status', '=', 'not sale');

            if ($request->has('q') && $request->q != '') {
                $q = $request->q;

                $displayQuery->where(function ($queryBuilder) use ($q) {
                    $queryBuilder->where('new_name_product', 'LIKE', "%{$q}%")
                        ->orWhere('new_barcode_product', 'LIKE', "%{$q}%")
                        ->orWhere('old_barcode_product', 'LIKE', "%{$q}%");
                });

                $bundleQuery->where(function ($queryBuilder) use ($q) {
                    $queryBuilder->where('name_bundle', 'LIKE', "%{$q}%")
                        ->orWhere('barcode_bundle', 'LIKE', "%{$q}%");
                });
            }

            $unionQuery = $displayQuery->unionAll($bundleQuery);

            $paginatedProducts = DB::query()
                ->fromSub($unionQuery, 'combined_table')
                ->orderBy('created_at', 'desc')
                ->paginate(50);

            $paginatedProducts->getCollection()->transform(function ($item) {
                $item->is_bundle = (bool) $item->is_bundle;
                return $item;
            });

            return (new ResponseResource(true, 'List Produk & Bundle Color Belum Masuk Rak', [
                'products'    => $paginatedProducts,
                'total_items' => $paginatedProducts->total(),
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }
}
