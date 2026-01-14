<?php

namespace App\Http\Controllers;

use App\Models\Rack;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            $query->withCount(['stagingProducts as live_total_data' => function ($q) use ($excludedStatuses) {
                $q->whereNotIn('new_status_product', $excludedStatuses);
            }]);
        } elseif ($request->has('source') && $request->source == 'display') {
            $query->withCount(['newProducts as live_total_data' => function ($q) use ($excludedStatuses) {
                $q->whereNotIn('new_status_product', $excludedStatuses);
            }]);
        }

        $racks = $query->latest()->paginate(10);

        $racks->getCollection()->transform(function ($rack) {
            if (isset($rack->live_total_data)) {
                $rack->total_data = $rack->live_total_data;
            }
            return $rack;
        });

        $totalRacks = Rack::when($request->has('source'), function ($q) use ($request) {
            $q->where('source', $request->source);
        })->count();

        $totalProductsInRacks = 0;

        if ($request->has('source')) {
            if ($request->source == 'staging') {
                $totalProductsInRacks = StagingProduct::whereNotNull('rack_id')
                    ->whereNotIn('new_status_product', $excludedStatuses)
                    ->count();
            } elseif ($request->source == 'display') {
                $totalProductsInRacks = New_product::whereNotNull('rack_id')
                    ->whereNotIn('new_status_product', $excludedStatuses)
                    ->count();
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
        $products = [];

        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale'];

        if ($rack->source === 'staging') {
            $baseProductQuery = $rack->stagingProducts()->whereNotIn('new_status_product', $excludedStatuses);
        } else {
            $baseProductQuery = $rack->newProducts()->whereNotIn('new_status_product', $excludedStatuses);
        }

        $rack->total_data = $baseProductQuery->count();
        $rack->total_new_price_product = (string) $baseProductQuery->sum('new_price_product');
        $rack->total_old_price_product = (string) $baseProductQuery->sum('old_price_product');
        $rack->total_display_price_product = (string) $baseProductQuery->sum('display_price');

        $listQuery = clone $baseProductQuery;

        if ($search) {
            $listQuery->where(function ($q) use ($search) {
                $q->where('new_name_product', 'like', '%' . $search . '%')
                    ->orWhere('new_barcode_product', 'like', '%' . $search . '%')
                    ->orWhere('old_barcode_product', 'like', '%' . $search . '%')
                    ->orWhere('code_document', 'like', '%' . $search . '%');
            });
        }

        $products = $listQuery->latest()->get();

        return new ResponseResource(true, 'Detail Data Rak dan Produk', [
            'rack_info' => $rack,
            'products'  => $products
        ]);
    }

    public function destroy($id)
    {
        $rack = Rack::find($id);

        if (!$rack) {
            return new ResponseResource(false, 'Rak tidak ditemukan', null);
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

    public function addStagingProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'product_id' => 'required|exists:staging_products,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $product = StagingProduct::find($request->product_id);

        if ($rack->source !== 'staging') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: ID Rak yang Anda pilih adalah rak tipe Display. Gunakan endpoint khusus display.',
                'resource' => null
            ], 422);
        }


        if ($product->rack_id != null) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk Staging ini sudah tersimpan di rak lain.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();
            $product->update(['rack_id' => $rack->id]);
            $this->recalculateRackTotals($rack);
            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak Staging: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }


    public function addDisplayProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rack_id' => 'required|exists:racks,id',
            'product_id' => 'required|exists:new_products,id',
            'new_barcode_product' => 'required|exists:new_products,new_barcode_product',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rack = Rack::find($request->rack_id);
        $product = New_product::find($request->product_id);

        if ($rack->source !== 'display') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: ID Rak yang Anda pilih adalah rak tipe Staging. Gunakan endpoint khusus staging.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != null) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk Display ini sudah tersimpan di rak lain.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();
            $product->update(['rack_id' => $rack->id]);
            $this->recalculateRackTotals($rack);
            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak Display: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    private function recalculateRackTotals($rack)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        if ($rack->source == 'staging') {
            $products = $rack->stagingProducts()
                ->whereNotIn('new_status_product', $excludedStatuses);

            $totalData = $products->count();
            $totalNewPrice = $products->sum('new_price_product');
            $totalOldPrice = $products->sum('old_price_product');
            $totalDisplayPrice = $products->sum('display_price');

            $rack->update([
                'total_data' => $totalData,
                'total_new_price_product' => $totalNewPrice,
                'total_old_price_product' => $totalOldPrice,
                'total_display_price_product' => $totalDisplayPrice,
            ]);
        } else {
            $products = $rack->newProducts()
                ->whereNotIn('new_status_product', $excludedStatuses);

            $totalData = $products->count();
            $totalNewPrice = $products->sum('new_price_product');
            $totalOldPrice = $products->sum('old_price_product');
            $totalDisplayPrice = $products->sum('display_price');

            $rack->update([
                'total_data' => $totalData,
                'total_new_price_product' => $totalNewPrice,
                'total_old_price_product' => $totalOldPrice,
                'total_display_price_product' => $totalDisplayPrice,
            ]);
        }
    }

    public function removeStagingProduct($rack_id, $product_id)
    {
        $rack = Rack::find($rack_id);
        $product = StagingProduct::find($product_id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan', 'resource' => null], 404);
        }
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Produk Staging tidak ditemukan', 'resource' => null], 404);
        }

        if ($rack->source !== 'staging') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ini bukan tipe Staging.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != $rack->id) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk ini tidak berada di rak tersebut.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product->update(['rack_id' => null]);
            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menghapus produk staging dari rak', $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
        }
    }

    public function removeDisplayProduct($rack_id, $product_id)
    {
        $rack = Rack::find($rack_id);
        $product = New_product::find($product_id);

        if (!$rack) {
            return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan', 'resource' => null], 404);
        }
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Produk Display tidak ditemukan', 'resource' => null], 404);
        }

        if ($rack->source !== 'display') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Rak ini bukan tipe Display.',
                'resource' => null
            ], 422);
        }

        if ($product->rack_id != $rack->id) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Produk ini tidak berada di rak tersebut.',
                'resource' => null
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product->update(['rack_id' => null]);
            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menghapus produk display dari rak', $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'resource' => null], 500);
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
                ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
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
                    $keywords = explode(',', $rackName);

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
                ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                ->where(function ($status) {
                    $status->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
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
                    $keywords = explode(',', $rackName);

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
            'source'  => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        $rack = Rack::find($request->rack_id);
        $barcode = $request->barcode;
        $source = $request->source;

        if ($rack->source != $source) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal: Source rak (' . $rack->source . ') tidak cocok dengan source input (' . $source . ').'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product = null;

            if ($source === 'staging') {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Staging tidak ditemukan dengan barcode: ' . $barcode], 404);
                }
            } else {
                $product = New_product::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if (!$product) {
                    return response()->json(['status' => false, 'message' => 'Produk Display tidak ditemukan dengan barcode: ' . $barcode], 404);
                }
            }

            $forbiddenStatuses = ['dump', 'sale', 'migrate', 'repair', 'scrap_qcd'];

            if (in_array($product->new_status_product, $forbiddenStatuses)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk dengan status "' . $product->new_status_product . '" tidak diperbolehkan masuk ke rak.'
                ], 422);
            }

            if (!empty($rack->name) && !empty($product->new_category_product)) {
                $rackName = strtoupper(trim($rack->name));
                $productCategoryName = strtoupper(trim($product->new_category_product));

                if (strpos($rackName, '-') !== false) {
                    $rackCategoryCore = substr($rackName, strpos($rackName, '-') + 1);
                } else {
                    $rackCategoryCore = $rackName;
                }

                $rackCategoryCore = preg_replace('/\s+\d+$/', '', $rackCategoryCore);
                $keywords = explode(',', $rackCategoryCore);
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
                        'message' => "Gagal: Produk '$productCategoryName' tidak sesuai dengan Rak '$rackName' (Kategori Rak: $rackCategoryCore).",
                    ], 422);
                }
            }

            if ($product->rack_id != null) {
                $currentRack = Rack::find($product->rack_id);
                return response()->json([
                    'status' => false,
                    'message' => 'Produk sudah berada di rak lain: ' . ($currentRack ? $currentRack->name : 'Unknown')
                ], 422);
            }

            $product->update(['rack_id' => $rack->id]);
            $this->recalculateRackTotals($rack);

            DB::commit();

            return new ResponseResource(true, 'Berhasil menambahkan produk ke Rak ' . $rack->name, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function moveAllProductsInRackToDisplay($rack_id)
    {

        $stagingRack = Rack::find($rack_id);

        if (!$stagingRack || $stagingRack->source !== 'staging') {
            return response()->json(['status' => false, 'message' => 'Rak Staging tidak valid.'], 422);
        }


        if (!$stagingRack->display_rack_id) {
            return response()->json(['status' => false, 'message' => 'Rak ini tidak memiliki tujuan Display (Lost link).'], 422);
        }

        $displayRack = Rack::find($stagingRack->display_rack_id);

        if (!$displayRack) {
            return response()->json(['status' => false, 'message' => 'Rak Display tujuan sudah dihapus.'], 404);
        }

        $query = $stagingRack->stagingProducts();

        if ($query->count() === 0) return response()->json(['status' => false, 'message' => 'Rak kosong.'], 422);

        try {
            DB::beginTransaction();

            $query->chunkById(100, function ($products) use ($displayRack) {
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
                if (!empty($idsToDelete)) $products->first()->newQuery()->whereIn('id', $idsToDelete)->delete();
            });

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
}
