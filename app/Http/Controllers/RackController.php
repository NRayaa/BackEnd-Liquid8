<?php

namespace App\Http\Controllers;

use App\Exports\RackHistoryExport;
use App\Models\Rack;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use App\Models\RackHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class RackController extends Controller
{
    public function index(Request $request)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $query = Rack::query();

        if ($request->has('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('barcode', 'like', '%' . $search . '%');
            });
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        if ($request->has('source') && $request->source == 'staging') {
            $query->withCount([
                'stagingProducts as staging_count' => function ($q) use ($excludedStatuses) {
                    $q->whereNotIn('new_status_product', $excludedStatuses);
                },
                'newProducts as display_count' => function ($q) use ($excludedStatuses) {
                    $q->whereNotIn('new_status_product', $excludedStatuses);
                }
            ]);
        } elseif ($request->has('source') && $request->source == 'display') {
            $query->withCount(['newProducts as live_total_data' => function ($q) use ($excludedStatuses) {
                $q->whereNotIn('new_status_product', $excludedStatuses);
            }]);
        }

        $racks = $query->latest()->paginate(10);

        $racks->getCollection()->transform(function ($rack) {
            if ($rack->source == 'staging') {
                // Jumlahkan kedua counter
                $countStaging = $rack->staging_count ?? 0;
                $countDisplay = $rack->display_count ?? 0;
                $rack->total_data = $countStaging + $countDisplay;
            } elseif (isset($rack->live_total_data)) {
                $rack->total_data = $rack->live_total_data;
            }
            $rack->is_so = (int) $rack->is_so;
            $rack->status_so = $rack->is_so == 1 ? 'Sudah SO' : 'Belum SO';
            return $rack;
        });

        $totalRacks = Rack::when($request->has('source'), function ($q) use ($request) {
            $q->where('source', $request->source);
        })->count();

        $totalProductsInRacks = 0;

        if ($request->has('source')) {
            if ($request->source == 'staging') {
                $count1 = StagingProduct::whereHas('rack', function ($q) {
                    $q->where('source', 'staging');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();

                $count2 = New_product::whereHas('rack', function ($q) {
                    $q->where('source', 'staging');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();

                $totalProductsInRacks = $count1 + $count2;
            } elseif ($request->source == 'display') {
                $totalProductsInRacks = New_product::whereHas('rack', function ($q) {
                    $q->where('source', 'display');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();
            }
        } else {
            $countStaging = StagingProduct::whereNotNull('rack_id')->whereNotIn('new_status_product', $excludedStatuses)->count();
            $countDisplay = New_product::whereNotNull('rack_id')->whereNotIn('new_status_product', $excludedStatuses)->count();
            $totalProductsInRacks = $countStaging + $countDisplay;
        }

        return new ResponseResource(true, 'List Data Rak', [
            'racks' => $racks,
            'total_racks' => $totalRacks,
            'total_products_in_racks' => (int) $totalProductsInRacks
        ]);
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, $validator->errors(),], 422);
        }

        try {
            $user_id = Auth::id();
            $source = $request->source;
            $finalName = '';
            $displayRackId = null;

            if ($source === 'display') {

                $validatorDisplay = Validator::make($request->all(), [
                    'name' => [
                        'required',
                        'string',
                        Rule::unique('racks')->where(function ($query) {
                            return $query->where('source', 'display');
                        })
                    ],
                ]);

                if ($validatorDisplay->fails()) {
                    return response()->json(['status' => false, 'message' => $validatorDisplay->errors()], 422);
                }

                $finalName = $request->name;
            } else {
                $validatorStaging = Validator::make($request->all(), [
                    'display_rack_id' => 'required|exists:racks,id',
                ]);

                if ($validatorStaging->fails()) {
                    return response()->json(['status' => false, 'message' => $validatorStaging->errors()], 422);
                }

                $parentRack = Rack::find($request->display_rack_id);

                if ($parentRack->source !== 'display') {
                    return response()->json(['status' => false, 'message' => 'Induk harus Rack Display.'], 422);
                }

                $sourceInitial = strtoupper(substr($source, 0, 1));
                $parentName = $parentRack->name;
                $prefixName = "{$sourceInitial}{$user_id}-{$parentName}";

                $latestRack = Rack::where('source', 'staging')
                    ->where('name', 'LIKE', "{$prefixName}%")
                    ->orderByRaw('LENGTH(name) DESC')
                    ->orderBy('name', 'DESC')
                    ->first();

                $nextNumber = 1;
                if ($latestRack) {
                    $parts = explode(' ', $latestRack->name);
                    $lastNumber = end($parts);
                    if (is_numeric($lastNumber)) {
                        $nextNumber = (int) $lastNumber + 1;
                    }
                }

                $finalName = "{$prefixName} {$nextNumber}";

                $displayRackId = $parentRack->id;
            }

            $sourceCode = strtoupper(substr($source, 0, 1));
            $randomString = strtoupper(Str::random(4));
            $generatedBarcode = $sourceCode . $user_id . '-' . $randomString;

            $rack = Rack::create([
                'name' => $finalName,
                'source' => $source,
                'display_rack_id' => $displayRackId,
                'total_data' => 0,
                'barcode' => $generatedBarcode
            ]);

            return new ResponseResource(true, 'Berhasil membuat rak: ' . $finalName, $rack);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['status' => false, 'message' => 'Gagal: Data duplikat ditemukan.'], 422);
            }
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function update(Request $request, $id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan'], 404);
        }

        if ($rack->is_so == 1) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ' . $rack->name . ' sudah di SO. Produk tidak bisa di edit.'
            ], 422);
        }

        $rules = [
            'name' => [
                'required',
                'string',
                Rule::unique('racks')->where(function ($query) use ($rack) {
                    return $query->where('source', $rack->source);
                })->ignore($rack->id),
            ],
        ];

        if ($rack->source === 'staging') {
            $rules['display_rack_id'] = 'required|exists:racks,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::beginTransaction();

            $nameToSave = $request->name;
            $updateData = [];

            if ($rack->source === 'staging') {
                $displayRack = Rack::find($request->display_rack_id);

                if ($displayRack->source !== 'display') {
                    return response()->json(['status' => false, 'message' => 'ID yang dipilih bukan rak display.'], 422);
                }

                $categoryName = $displayRack->name;

                $userId = auth()->id();

                $baseFormat = "S{$userId}-{$categoryName}";

                $count = Rack::where('source', 'staging')
                    ->where('name', 'LIKE', "{$baseFormat}%")
                    ->where('id', '!=', $rack->id)
                    ->count();

                $sequence = $count + 1;
                $nameToSave = "{$baseFormat} {$sequence}";

                $updateData['name'] = $nameToSave;
                $updateData['display_rack_id'] = $displayRack->id;
            } else {
                $updateData['name'] = $request->name;
            }

            $rack->update($updateData);

            DB::commit();

            return new ResponseResource(true, 'Berhasil memperbarui nama rak: ' . $nameToSave, $rack);
        } catch (QueryException $e) {
            DB::rollback();
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['status' => false, 'message' => 'Nama rak sudah digunakan/terduplikasi.'], 422);
            }
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return response()->json([
                'status' => false,
                'message' => 'Rak tidak ditemukan',
                'resource' => null
            ], 404);
        }

        $search = $request->q;
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];


        $stagingQuery = $rack->stagingProducts()->whereNotIn('new_status_product', $excludedStatuses);
        $inventoryQuery = $rack->newProducts()->whereNotIn('new_status_product', $excludedStatuses);


        if ($search) {
            $searchLogic = function ($q) use ($search) {
                $q->where('new_name_product', 'like', '%' . $search . '%')
                    ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                    ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                    ->orWhere('code_document', 'like', '%' . $search . '%');
            };

            $stagingQuery->where($searchLogic);
            $inventoryQuery->where($searchLogic);
        }


        $stagingProducts = $stagingQuery->latest()->get();
        $inventoryProducts = $inventoryQuery->latest()->get();


        $stagingProducts->transform(function ($item) {
            $item->source = 'staging';
            return $item;
        });

        $inventoryProducts->transform(function ($item) {
            $item->source = 'display';
            return $item;
        });

        $finalProducts = collect();

        if ($rack->source === 'staging') {

            $finalProducts = $stagingProducts->merge($inventoryProducts);
        } else {

            $finalProducts = $inventoryProducts;
        }

        $rack->total_data = $finalProducts->count();
        $rack->total_new_price_product = (string) $finalProducts->sum('new_price_product');
        $rack->total_old_price_product = (string) $finalProducts->sum('old_price_product');
        $rack->total_display_price_product = (string) $finalProducts->sum('display_price');

        $page = $request->get('page', 1);
        $perPage = 50;

        $paginatedItems = new \Illuminate\Pagination\LengthAwarePaginator(
            $finalProducts->forPage($page, $perPage)->values(),
            $finalProducts->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $paginatedItems->getCollection()->transform(function ($item) {
            $item->status_so = ($item->is_so === 'done') ? 'Sudah SO' : 'Belum SO';
            return $item;
        });

        return new ResponseResource(true, 'Detail Data Rak dan Produk', [
            'rack_info' => $rack,
            'products'  => $paginatedItems
        ]);
    }

    public function destroy($id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return new ResponseResource(false, 'Rak tidak ditemukan', null);
        }

        if ($rack->is_so == 1) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ' . $rack->name . ' sudah di SO. Produk tidak bisa di hapus.'
            ], 422);
        }

        try {
            DB::beginTransaction();
            if ($rack->source === 'display') {
                Rack::where('source', 'staging')
                    ->where('display_rack_id', $rack->id)
                    ->update(['display_rack_id' => null]);
            }

            if ($rack->total_data > 0) {
                if (in_array($rack->source, ['staging', 'display'])) {
                    $model = $rack->source === 'staging' ? StagingProduct::class : New_product::class;
                    $model::where('rack_id', $rack->id)->update(['rack_id' => null]);
                }
            }

            $rack->delete();

            DB::commit();
            return new ResponseResource(true, 'Berhasil hapus rak', null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => 'Gagal menghapus rak: ' . $e->getMessage()], 500);
        }
    }

    private function recalculateRackTotals($rack)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $stagingQuery = $rack->stagingProducts()
            ->whereNotIn('new_status_product', $excludedStatuses);

        $inventoryQuery = $rack->newProducts()
            ->whereNotIn('new_status_product', $excludedStatuses);

        if ($rack->source == 'staging') {
            $totalData = $stagingQuery->count() + $inventoryQuery->count();
            $totalNewPrice = $stagingQuery->sum('new_price_product') + $inventoryQuery->sum('new_price_product');
            $totalOldPrice = $stagingQuery->sum('old_price_product') + $inventoryQuery->sum('old_price_product');
            $totalDisplayPrice = $stagingQuery->sum('display_price') + $inventoryQuery->sum('display_price');

            $rack->update([
                'total_data' => $totalData,
                'total_new_price_product' => (string) $totalNewPrice,
                'total_old_price_product' => (string) $totalOldPrice,
                'total_display_price_product' => (string) $totalDisplayPrice,
            ]);
        } else {
            $products = $inventoryQuery;
            $rack->update([
                'total_data' => $products->count(),
                'total_new_price_product' => (string) $products->sum('new_price_product'),
                'total_old_price_product' => (string) $products->sum('old_price_product'),
                'total_display_price_product' => (string) $products->sum('display_price'),
            ]);
        }
    }

    public function removeProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id'    => 'required|exists:racks,id',
            'product_id' => 'required',
            'source'     => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $productId = $request->product_id;
        $source = $request->source;

        $userId = Auth::id();

        if ($rack->is_so == 1) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ' . $rack->name . ' sudah di SO. Produk tidak boleh dihapus atau dikeluarkan.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product = null;

            if ($source === 'staging') {
                $product = StagingProduct::find($productId);
                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Staging tidak ditemukan.'], 404);
                }
            } else {
                $product = New_product::find($productId);
                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Inventory/Display tidak ditemukan.'], 404);
                }
            }

            if ($product->rack_id != $rack->id) {
                $currentRack = Rack::find($product->rack_id);
                $currentRackName = $currentRack ? $currentRack->name : 'Rak Lain';

                return response()->json([
                    'status' => false,
                    'message' => "Gagal: Produk ini tidak berada di rak {$rack->name}, melainkan di {$currentRackName}."
                ], 422);
            }

            $product->update([
                'rack_id' => null,
                'user_so' => null
            ]);

            $this->recalculateRackTotals($rack);

            RackHistory::create([
                'user_id'      => $userId,
                'rack_id'      => $rack->id,
                'product_id'   => $product->id,
                'barcode'      => $product->new_barcode_product,
                'product_name' => $product->new_name_product,
                'action'       => 'OUT',
                'source'       => $source
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil mengeluarkan produk {$source} dari rak", $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function listStagingProducts(Request $request)
    {
        $search = $request->q;
        $rackId = $request->rack_id;

        try {
            $query = StagingProduct::query()
                ->select(
                    'id',
                    'new_name_product',
                    'new_barcode_product',
                    'old_barcode_product',
                    'new_category_product',
                    'code_document',
                )
                ->whereNull('rack_id')
                ->whereNotNull('new_category_product')
                ->where('new_tag_product', NULL)
                ->where(function ($query) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
                })
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired')
                        ->orWhere('new_status_product', 'slow_moving');
                })->where(function ($type) {
                    $type->whereNull('type')
                        ->orWhere('type', 'type1')
                        ->orWhere('type', 'type2');
                });

            if ($rackId) {
                $rack = Rack::find($rackId);

                if ($rack) {
                    $rackName = strtoupper(trim($rack->name));

                    if (strpos($rackName, '-') !== false) {
                        $rackName = substr($rackName, strpos($rackName, '-') + 1);
                    }

                    $rackName = preg_replace('/\s+\d+$/', '', $rackName);
                    $keywords = preg_split('/[\s,]+/', $rackName, -1, PREG_SPLIT_NO_EMPTY);

                    $query->where(function ($q) use ($keywords) {
                        foreach ($keywords as $keyword) {

                            $cleanKeyword = trim($keyword);
                            if (!empty($cleanKeyword)) {
                                $q->orWhere('new_category_product', 'LIKE', '%' . $cleanKeyword . '%');
                            }
                        }
                    });
                }
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('new_category_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $stagingProducts = $query->latest()->paginate(50);

            return new ResponseResource(true, 'List Produk Staging Belum Masuk Rak', [
                'products' => $stagingProducts,
                'count' => $stagingProducts->total(),
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function listDisplayProducts(Request $request)
    {
        $search = $request->q;
        $rackId = $request->rack_id;

        try {
            $query = New_product::query()
                ->select(
                    'id',
                    'new_name_product',
                    'new_barcode_product',
                    'old_barcode_product',
                    'new_category_product',
                    'code_document',
                )
                ->whereNull('rack_id')
                ->whereNotNull('new_category_product')
                ->where('new_tag_product', NULL)
                ->where(function ($query) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
                })
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired')
                        ->orWhere('new_status_product', 'slow_moving');
                })->where(function ($type) {
                    $type->whereNull('type')
                        ->orWhere('type', 'type1')
                        ->orWhere('type', 'type2');
                });

            if ($rackId) {
                $rack = Rack::find($rackId);

                if ($rack) {
                    $rackName = strtoupper(trim($rack->name));

                    if (strpos($rackName, '-') !== false) {
                        $rackName = substr($rackName, strpos($rackName, '-') + 1);
                    }

                    $rackName = preg_replace('/\s+\d+$/', '', $rackName);
                    $keywords = preg_split('/[\s,]+/', $rackName, -1, PREG_SPLIT_NO_EMPTY);

                    $query->where(function ($q) use ($keywords) {
                        foreach ($keywords as $keyword) {
                            $cleanKeyword = trim($keyword);
                            if (!empty($cleanKeyword)) {
                                $q->orWhere('new_category_product', 'LIKE', '%' . $cleanKeyword . '%');
                            }
                        }
                    });
                }
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('new_name_product', 'like', '%' . $search . '%')
                        ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                        ->orWhere('new_category_product', 'like', '%' . $search . '%')
                        ->orWhere('code_document', 'like', '%' . $search . '%');
                });
            }

            $displayProducts = $query->latest()->paginate(50);

            return new ResponseResource(true, 'List Produk Display Belum Masuk Rak', [
                'products' => $displayProducts,
                'count' => $displayProducts->total(),
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan server", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function addProductByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        $rack = Rack::find($request->rack_id);
        $barcode = $request->barcode;

        $userId = Auth::id();

        if ($rack->is_so == 1) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ' . $rack->name . ' sedang dalam status SO. Tidak dapat menambah produk.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product = null;
            $originSource = '';

            $product = StagingProduct::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $originSource = 'staging';
            } else {
                $product = New_product::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if ($product) {
                    $originSource = 'display';
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk tidak ditemukan di data Staging maupun Inventory dengan barcode: ' . $barcode
                ], 404);
            }

            if (!empty($product->new_tag_product)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ini terdeteksi sebagai Produk Color (Memiliki Tag: ' . $product->new_tag_product . '). Tidak bisa masuk Rak.'
                ], 422);
            }

            $forbiddenStatuses = ['dump', 'sale', 'migrate', 'repair', 'scrap_qcd'];
            if (in_array($product->new_status_product, $forbiddenStatuses)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk dengan status "' . $product->new_status_product . '" dilarang masuk rak.'
                ], 422);
            }

            $quality = $product->new_quality;
            if (is_string($quality)) {
                $quality = json_decode($quality, true);
            }

            if (is_array($quality)) {
                if (empty($quality['lolos'])) {
                    $failReason = 'Kualitas tidak memenuhi syarat (Bukan Lolos)';
                    if (!empty($quality['abnormal'])) $failReason = "Abnormal: " . $quality['abnormal'];
                    elseif (!empty($quality['damaged'])) $failReason = "Damaged: " . $quality['damaged'];
                    elseif (!empty($quality['non'])) $failReason = "Non: " . $quality['non'];

                    return response()->json([
                        'status' => false,
                        'message' => "Gagal: Produk tidak bisa masuk rak. Status $failReason."
                    ], 422);
                }
            }

            if (!empty($rack->name) && !empty($product->new_category_product)) {
                $rackName = strtoupper(trim($rack->name));
                $productCategoryName = strtoupper(trim($product->new_category_product));

                $rackCategoryCore = (strpos($rackName, '-') !== false)
                    ? substr($rackName, strpos($rackName, '-') + 1)
                    : $rackName;
                $rackCategoryCore = preg_replace('/\s+\d+$/', '', $rackCategoryCore);

                $keywords = preg_split('/[\s,]+/', $rackCategoryCore, -1, PREG_SPLIT_NO_EMPTY);
                $isMatch = false;

                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if (!empty($cleanKeyword) && strpos($productCategoryName, $cleanKeyword) !== false) {
                        $isMatch = true;
                        break;
                    }
                }

                if (!$isMatch) {
                    return response()->json([
                        'status' => false,
                        'message' => "Gagal: Kategori produk '$productCategoryName' tidak cocok dengan Rak '$rackName'.",
                    ], 422);
                }
            }

            if ($product->rack_id != null) {
                if ($product->rack_id == $rack->id) {
                    return response()->json(['status' => false, 'message' => 'Produk sudah berada di rak ini.'], 422);
                }
                $currentRack = Rack::find($product->rack_id);
                return response()->json([
                    'status' => false,
                    'message' => 'Produk sudah berada di rak lain: ' . ($currentRack ? $currentRack->name : 'Unknown')
                ], 422);
            }

            if ($originSource === 'display') {
                if ($rack->source === 'staging') {
                    $productData = $product->toArray();
                    unset($productData['id']);
                    unset($productData['created_at']);
                    unset($productData['updated_at']);

                    $productData['rack_id'] = $rack->id;
                    $productData['user_so'] = $userId;

                    $newStagingProduct = StagingProduct::create($productData);
                    $product->delete();
                    $product = $newStagingProduct;
                    $originSource = 'moved_from_display_to_staging';
                } else {
                    $product->update([
                        'rack_id' => $rack->id,
                        'user_so' => $userId
                    ]);
                }
            } else {
                if ($rack->source === 'display') {
                    return response()->json([
                        'status' => false,
                        'message' => 'Gagal: Produk Staging dilarang masuk langsung ke Rak Display. Harap gunakan Rak Staging.'
                    ], 422);
                } else {
                    $product->update([
                        'rack_id' => $rack->id,
                        'user_so' => $userId
                    ]);
                }
            }

            $this->recalculateRackTotals($rack);
            RackHistory::create([
                'user_id'      => $userId,
                'rack_id'      => $rack->id,
                'product_id'   => $product->id,
                'barcode'      => $product->new_barcode_product,
                'product_name' => $product->new_name_product,
                'action'       => 'IN',
                'source'       => $originSource
            ]);

            DB::commit();

            $product->origin_source = $originSource;

            return new ResponseResource(true, 'Berhasil masuk ke Rak ' . $rack->name, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function moveAllProductsInRackToDisplay($rack_id)
    {
        $stagingRack = Rack::find($rack_id);

        if (!$stagingRack || $stagingRack->source !== 'staging') {
            return response()->json(['status' => false, 'message' => 'Rak asal bukan tipe Staging atau tidak ditemukan.'], 422);
        }

        if (!$stagingRack->display_rack_id) {
            return response()->json(['status' => false, 'message' => 'Rak ini tidak memiliki tujuan Display (Lost link).'], 422);
        }

        $displayRack = Rack::find($stagingRack->display_rack_id);

        if (!$displayRack) {
            return response()->json(['status' => false, 'message' => 'Rak Display tujuan sudah dihapus.'], 404);
        }

        if ($stagingRack->is_so == 0) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ' . $stagingRack->name . ' belum di SO. Tidak dapat dipindah ke Display.'
            ], 422);
        }

        $countStaging = $stagingRack->stagingProducts()->count();
        $countInventory = $stagingRack->newProducts()->count();

        if ($countStaging === 0 && $countInventory === 0) {
            return response()->json(['status' => false, 'message' => 'Rak kosong.'], 422);
        }

        try {
            DB::beginTransaction();

            $queryStaging = $stagingRack->stagingProducts();

            if ($queryStaging->count() > 0) {
                $queryStaging->chunkById(100, function ($products) use ($displayRack) {
                    $dataToInsert = [];
                    $idsToDelete = [];
                    $now = now();

                    foreach ($products as $stagingProduct) {
                        $dataToInsert[] = [
                            'code_document'        => $stagingProduct->code_document,
                            'old_barcode_product'  => $stagingProduct->old_barcode_product,
                            'new_barcode_product'  => $stagingProduct->new_barcode_product,
                            'new_name_product'     => $stagingProduct->new_name_product,
                            'new_quantity_product' => $stagingProduct->new_quantity_product,
                            'new_price_product'    => $stagingProduct->new_price_product,
                            'old_price_product'    => $stagingProduct->old_price_product,
                            'new_date_in_product'  => $stagingProduct->new_date_in_product,
                            'new_status_product'   => $stagingProduct->new_status_product,
                            'new_quality'          => $stagingProduct->new_quality,
                            'new_category_product' => $stagingProduct->new_category_product,
                            'new_tag_product'      => $stagingProduct->new_tag_product,
                            'display_price'        => $stagingProduct->display_price,
                            'new_discount'         => $stagingProduct->new_discount,
                            'type'                 => $stagingProduct->type,
                            'user_id'              => $stagingProduct->user_id,
                            'is_so'                => $stagingProduct->is_so,
                            'user_so'              => $stagingProduct->user_so,
                            'actual_old_price_product' => $stagingProduct->actual_old_price_product,
                            'actual_new_quality'   => $stagingProduct->actual_new_quality,
                            'rack_id'              => $displayRack->id,
                            'created_at'           => $stagingProduct->created_at,
                            'updated_at'           => $now,
                        ];
                        $idsToDelete[] = $stagingProduct->id;
                    }

                    if (!empty($dataToInsert)) New_product::insert($dataToInsert);
                    if (!empty($idsToDelete)) StagingProduct::whereIn('id', $idsToDelete)->delete();
                });
            }

            New_product::where('rack_id', $stagingRack->id)
                ->update(['rack_id' => $displayRack->id]);

            $this->recalculateRackTotals($stagingRack);
            $this->recalculateRackTotals($displayRack);

            DB::commit();

            return new ResponseResource(true, "Produk berhasil dipindah ke " . $displayRack->name, null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getRackList(Request $request)
    {
        $query = Rack::query()->select('id', 'name');

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        if ($request->has('q')) {
            $query->where('name', 'like', '%' . $request->q . '%');
        }

        if ($request->has('available_for_staging') && $request->available_for_staging == '1') {
            $takenNames = Rack::where('source', 'staging')->pluck('name')->toArray();

            $query->where('source', 'display')
                ->whereNotIn('name', $takenNames);
        }

        $racks = $query->orderBy('name', 'asc')->get();

        return new ResponseResource(true, 'List Nama Rak', $racks);
    }

    public function getRackHistory(Request $request)
    {
        try {
            $query = RackHistory::with(['user:id,name', 'rack:id,name']);

            if ($request->has('rack_id') && $request->rack_id != '') {
                $query->where('rack_id', $request->rack_id);
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
                    'source'       => $log->source,
                    'rack_name'    => $log->rack ? $log->rack->name : 'Rak Dihapus/Unknown',
                    'operator'     => $log->user ? $log->user->name : 'Sistem/Unknown',
                    'tanggal'      => $log->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return new ResponseResource(true, 'Berhasil mengambil history rak', $history);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function getRackInsertionStats(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        $source = $request->source;
        $search = $request->q;
        $perPage = (int) $request->input('per_page', 30);
        $page = (int) $request->input('page', 1);

        try {
            $latestHistoryIds = RackHistory::select(DB::raw('MAX(id) as id'))
                ->groupBy('barcode');

            $query = RackHistory::with(['user:id,name', 'rack:id,name'])
                ->whereIn('id', $latestHistoryIds)
                ->where('action', 'IN')
                ->whereHas('rack', function ($qRack) use ($source) {
                    $qRack->where('source', $source);
                });

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('rack', function ($qRack) use ($search) {
                        $qRack->where('name', 'like', "%{$search}%");
                    })
                        ->orWhereHas('user', function ($qUser) use ($search) {
                            $qUser->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $validInsertions = $query->select(
                'rack_id',
                'user_id',
                DB::raw('COUNT(*) as total_inserted')
            )
                ->groupBy('rack_id', 'user_id')
                ->get();

            $totalAllUsers = $validInsertions->sum('total_inserted');

            $formattedData = [];

            foreach ($validInsertions as $row) {
                $rackName = $row->rack ? $row->rack->name : 'Rak Telah Dihapus/Unknown';
                $userName = $row->user ? $row->user->name : 'Sistem / Unknown';

                if (!isset($formattedData[$rackName])) {
                    $formattedData[$rackName] = [
                        'rack_id'       => $row->rack_id,
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
                [
                    'path'  => $request->url(),
                    'query' => $request->query()
                ]
            );

            return response()->json([
                'status'  => true,
                'message' => "Statistik keseluruhan produk masuk di Rak " . ucfirst($source),
                'data'    => [
                    'source'          => $source,
                    'total_all_users' => $totalAllUsers,
                    'details'         => $paginator
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportRackHistory(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display',
            'date'   => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, "Validasi gagal", $validator->errors()))
                ->response()->setStatusCode(422);
        }

        $source = $request->source;

        $date = $request->input('date', \Carbon\Carbon::today()->toDateString());

        try {
            $folderName = 'exports/rack_stats';
            $fileName = 'DETAIL_RAK_' . strtoupper($source) . '_' . $date . '.xlsx';
            $filePath = $folderName . '/' . $fileName;

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            Excel::store(new RackHistoryExport($source, $date), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return (new ResponseResource(true, "File Statistik Rak {$source} berhasil diexport", [
                'download_url' => $downloadUrl,
                'file_name'    => $fileName
            ]))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }
}
