<?php

namespace App\Http\Controllers;

use App\Exports\ColorRackDataExport;
use App\Exports\ColorRackHistoryExport;
use Illuminate\Http\Request;
use App\Models\ColorRack;
use App\Models\ColorRackProduct;
use App\Models\New_product;
use App\Http\Resources\ResponseResource;
use App\Models\ColorRackHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

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
                "is_so"              => (bool) $rack->is_so,
                "status_so"          => $rack->is_so ? 'Sudah SO' : 'Belum SO',
                "so_at"              => $rack->so_at ? $rack->so_at->format('Y-m-d H:i:s') : null,
                "move_to_migrate_at" => $rack->move_to_migrate_at,
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

        $rack->load([
            'colorRackProducts.newProduct',
            'colorRackProducts.bundle',
            'userSo',
            'userMigrate'
        ]);

        $rackInfo = [
            'id'              => $rack->id,
            'name'            => $rack->name,
            'barcode'         => $rack->barcode,
            'status'          => $rack->status,
            'is_so'              => (bool) $rack->is_so,
            'status_so'          => $rack->is_so ? 'Sudah SO' : 'Belum SO',
            'so_at'              => $rack->so_at ? $rack->so_at->format('Y-m-d H:i:s') : null,
            'user_so_name'       => $rack->userSo ? $rack->userSo->name : null,
            'move_to_migrate_at' => $rack->move_to_migrate_at,
            'user_migrate_name'  => $rack->userMigrate ? $rack->userMigrate->name : null,
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

    public function destroy($id)
    {
        $rack = ColorRack::find($id);

        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))
                ->response()->setStatusCode(404);
        }

        if ($rack->status === 'process') {
            return (new ResponseResource(false, 'Tidak bisa menghapus rak yang sudah di-migrate', null))
                ->response()->setStatusCode(400);
        }

        if ($rack->status === 'migrate') {
            return (new ResponseResource(false, 'Tidak bisa menghapus rak yang sudah di-migrate', null))
                ->response()->setStatusCode(400);
        }

        DB::beginTransaction();
        try {
            $rack->delete();

            DB::commit();
            return (new ResponseResource(true, 'Color Rack beserta relasi produk di dalamnya berhasil dihapus', null))
                ->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, 'Gagal menghapus rak: ' . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
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

        // if (!$rack->is_so) {
        //     return (new ResponseResource(false, 'Rak harus di-SO terlebih dahulu sebelum migrate', null))->response()->setStatusCode(400); 
        // }

        $rack->update([
            'status' => 'process',
            'move_to_migrate_at' => now(),
            'user_migrate'       => auth()->id(),
        ]);

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

            ColorRackHistory::create([
                'user_id'       => Auth::id(),
                'color_rack_id' => $rack->id,
                'barcode'       => $bundle ? $bundle->barcode_bundle : $product->new_barcode_product,
                'product_name'  => $bundle ? "[BUNDLE] " . $bundle->name_bundle : $product->new_name_product,
                'action'        => 'IN',
            ]);

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

            ColorRackHistory::create([
                'user_id'       => Auth::id(),
                'color_rack_id' => $rack->id,
                'barcode'       => $bundle ? $itemDetail->barcode_bundle : $itemDetail->new_barcode_product,
                'product_name'  => $bundle ? "[BUNDLE] " . $itemDetail->name_bundle : $itemDetail->new_name_product,
                'action'        => 'OUT',
            ]);

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

    public function getRackHistory(Request $request)
    {
        try {
            $query = ColorRackHistory::with(['user:id,name', 'colorRack:id,name']);

            if ($request->has('rack_id') && $request->rack_id != '') {
                $query->where('color_rack_id', $request->rack_id);
            }

            if ($request->has('barcode') && $request->barcode != '') {
                $query->where('barcode', 'like', '%' . $request->barcode . '%');
            }

            if ($request->has('action') && $request->action != '') {
                $query->where('action', strtoupper($request->action));
            }

            $history = $query->latest()->paginate(50);

            $history->getCollection()->transform(function ($log) {
                return [
                    'id'           => $log->id,
                    'action'       => $log->action,
                    'action_label' => $log->action === 'IN' ? 'Barang Masuk Rak' : 'Barang Keluar Rak',
                    'barcode'      => $log->barcode,
                    'product_name' => $log->product_name,
                    'rack_name'    => $log->colorRack ? $log->colorRack->name : 'Rak Dihapus/Unknown',
                    'operator'     => $log->user ? $log->user->name : 'Sistem/Unknown',
                    'tanggal'      => $log->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return (new ResponseResource(true, 'Berhasil mengambil history rak color', $history))
                ->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, 'Gagal mengambil history: ' . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function getRackInsertionStats(Request $request)
    {
        $search = $request->q;
        $perPage = (int) $request->input('per_page', 30);
        $page = (int) $request->input('page', 1);

        try {
            $latestHistoryIds = ColorRackHistory::select(DB::raw('MAX(id) as id'))
                ->groupBy('barcode');

            $query = ColorRackHistory::with(['user:id,name', 'colorRack:id,name'])
                ->whereIn('id', $latestHistoryIds)
                ->where('action', 'IN');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('colorRack', function ($qRack) use ($search) {
                        $qRack->where('name', 'like', "%{$search}%");
                    })->orWhereHas('user', function ($qUser) use ($search) {
                        $qUser->where('name', 'like', "%{$search}%");
                    });
                });
            }

            $validInsertions = $query->select(
                'color_rack_id',
                'user_id',
                DB::raw('COUNT(*) as total_inserted')
            )->groupBy('color_rack_id', 'user_id')->get();

            $totalAllUsers = $validInsertions->sum('total_inserted');
            $formattedData = [];

            foreach ($validInsertions as $row) {
                $rackName = $row->colorRack ? $row->colorRack->name : 'Rak Telah Dihapus/Unknown';
                $userName = $row->user ? $row->user->name : 'Sistem / Unknown';

                if (!isset($formattedData[$rackName])) {
                    $formattedData[$rackName] = [
                        'rack_id'       => $row->color_rack_id,
                        'rack_name'     => $rackName,
                        'total_in_rack' => 0,
                        'users'         => []
                    ];
                }

                $formattedData[$rackName]['users'][] = [
                    'user_id'        => $row->user_id,
                    'user_name'      => $userName,
                    'total_inserted' => $row->total_inserted
                ];

                $formattedData[$rackName]['total_in_rack'] += $row->total_inserted;
            }

            $formattedData = array_values($formattedData);
            $offset = ($page - 1) * $perPage;
            $paginatedItems = array_slice($formattedData, $offset, $perPage);

            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $paginatedItems,
                count($formattedData),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return response()->json([
                'status'  => true,
                'message' => "Statistik keseluruhan produk masuk di Color Rack",
                'data'    => [
                    'total_all_users' => $totalAllUsers,
                    'details'         => $paginator
                ]
            ], 200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, 'Gagal mengambil statistik: ' . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function exportRackHistory(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Validasi gagal", $validator->errors()))->response()->setStatusCode(422);
        }

        $date = $request->input('date', \Carbon\Carbon::today()->toDateString());

        try {
            $folderName = 'exports/color_rack_stats';
            $fileName = 'DETAIL_COLOR_RAK_' . $date . '.xlsx';
            $filePath = $folderName . '/' . $fileName;

            if (!Storage::disk('public_direct')->exists($folderName)) {
                Storage::disk('public_direct')->makeDirectory($folderName);
            }

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new ColorRackHistoryExport($date), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return (new ResponseResource(true, "File Statistik Color Rak berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name'    => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function exportRacks(Request $request)
    {
        try {
            $folderName = 'exports/color_racks';
            $fileName = "DATA_COLOR_RAK_" . date('Ymd') . ".xlsx";
            $filePath = $folderName . '/' . $fileName;

            if (!Storage::disk('public_direct')->exists($folderName)) {
                Storage::disk('public_direct')->makeDirectory($folderName);
            }

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new ColorRackDataExport(), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return (new ResponseResource(true, "File Data Color Rak berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name'    => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }
}
