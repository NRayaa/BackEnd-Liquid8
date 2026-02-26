<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BulkyDocument;
use App\Models\Bundle;
use App\Models\MigrateBulkyProduct;
use App\Models\New_product;
use App\Models\Rack;
use App\Models\RackHistory;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductSoController extends Controller
{
    // staging
    public function soStagingProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();

            $product = StagingProduct::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk staging tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $quality = $product->new_quality;
            if (is_string($quality)) $quality = json_decode($quality, true);

            if (is_array($quality) && empty($quality['lolos'])) {
                $failReason = 'Kualitas tidak memenuhi syarat';
                if (!empty($quality['abnormal'])) $failReason = "Abnormal: " . $quality['abnormal'];
                elseif (!empty($quality['damaged'])) $failReason = "Damaged: " . $quality['damaged'];
                elseif (!empty($quality['non'])) $failReason = "Non-Kategori: " . $quality['non'];

                return response()->json(['status' => false, 'message' => "Gagal SO: $failReason"], 422);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil SO Produk: ' . $product->new_name_product, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // display
    public function soDisplayProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();
            $product = null;
            $source = '';

            $product = New_product::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $source = 'display';

                if ($product->is_so === 'done') {
                    return response()->json([
                        'status' => false,
                        'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya (Status: Display).'
                    ], 422);
                }

                $quality = $product->new_quality;
                if (is_string($quality)) $quality = json_decode($quality, true);

                if (is_array($quality) && empty($quality['lolos'])) {
                    $failReason = 'Kualitas tidak memenuhi syarat';
                    if (!empty($quality['abnormal'])) $failReason = "Abnormal: " . $quality['abnormal'];
                    elseif (!empty($quality['damaged'])) $failReason = "Damaged: " . $quality['damaged'];
                    elseif (!empty($quality['non'])) $failReason = "Non-Kategori: " . $quality['non'];

                    return response()->json(['status' => false, 'message' => "Gagal SO: $failReason"], 422);
                }

                $product->update([
                    'is_so' => 'done',
                    'user_so' => $user->id
                ]);
            } else {
                $stagingProduct = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if ($stagingProduct) {
                    $source = 'staging_moved_to_display';

                    $quality = $stagingProduct->new_quality;
                    if (is_string($quality)) $quality = json_decode($quality, true);

                    if (is_array($quality) && empty($quality['lolos'])) {
                        $failReason = 'Kualitas tidak memenuhi syarat';
                        if (!empty($quality['abnormal'])) $failReason = "Abnormal: " . $quality['abnormal'];
                        elseif (!empty($quality['damaged'])) $failReason = "Damaged: " . $quality['damaged'];
                        elseif (!empty($quality['non'])) $failReason = "Non-Kategori: " . $quality['non'];

                        return response()->json(['status' => false, 'message' => "Gagal SO & Pindah: $failReason"], 422);
                    }
                    $productData = $stagingProduct->toArray();

                    unset($productData['id']);
                    unset($productData['created_at']);
                    unset($productData['updated_at']);

                    $productData['is_so'] = 'done';
                    $productData['user_so'] = $user->id;

                    $product = New_product::create($productData);

                    $stagingProduct->delete();
                } else {
                    $product = Bundle::where('barcode_bundle', $barcode)->first();

                    if ($product) {
                        $source = 'bundle';
                        if ($product->is_so === 'done') {
                            return response()->json([
                                'status' => false,
                                'message' => 'Gagal: Produk Bundle ' . $product->name_bundle . ' sudah di SO sebelumnya.'
                            ], 422);
                        }

                        $product->update([
                            'is_so' => 'done',
                            'user_so' => $user->id
                        ]);
                    }
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk tidak ditemukan di Display, Staging, maupun Bundle dengan barcode: ' . $barcode
                ], 404);
            }

            DB::commit();

            $productName = $product->new_name_product ?? $product->name_bundle;

            return new ResponseResource(true, "Berhasil SO ({$source}): " . $productName, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // rack
    public function actionSo($id)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $rack = Rack::find($id);

            if (!$rack) {
                return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan'], 404);
            }

            if ($rack->is_so == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Rak ' . $rack->name . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $rack->update([
                'is_so' => 1,
                'user_so' => $user->id,
                'so_at' => now()
            ]);

            New_product::where('rack_id', $rack->id)->update([
                'is_so' => 'done',
            ]);

            StagingProduct::where('rack_id', $rack->id)->update([
                'is_so' => 'done',
            ]);

            Bundle::where('rack_id', $rack->id)->update([
                'is_so' => 'done',
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil melakukan SO pada rak: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function soRackByBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();

            $rack = Rack::where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode);
            })->first();

            if (!$rack) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk staging tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($rack->is_so == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Rak ' . $rack->name . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $rack->update([
                'is_so' => 1,
                'user_so' => $user->id,
                'so_at' => now()
            ]);

            $updateData = [
                'is_so' => 'done',
            ];

            New_product::where('rack_id', $rack->id)->update($updateData);
            StagingProduct::where('rack_id', $rack->id)->update($updateData);

            DB::commit();

            return new ResponseResource(true, 'Berhasil melakukan SO pada rak: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function soScanInDisplayRack(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
            'rack_id' => 'required|exists:racks,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $targetRackId = $request->rack_id;
            $user = Auth::user();

            $targetRack = Rack::find($targetRackId);

            if ($targetRack->source !== 'display') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Rak tujuan bukan Rak Display.'
                ], 422);
            }

            if ($targetRack->is_so == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Rak Display tujuan sedang terkunci (Sudah SO).'
                ], 422);
            }

            $product = null;
            $sourceType = '';
            $isBundle = false;

            $product = New_product::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $sourceType = 'display';
            } else {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if ($product) {
                    $sourceType = 'staging';
                } else {
                    $product = Bundle::where('barcode_bundle', $barcode)->first();
                    if ($product) {
                        $sourceType = 'bundle';
                        $isBundle = true;
                    }
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk/Bundle tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done' && $product->rack_id == $targetRackId) {
                $namaProd = $isBundle ? $product->name_bundle : $product->new_name_product;
                return response()->json([
                    'status' => false,
                    'message' => "Gagal: {$namaProd} sudah berada di Rak {$targetRack->name} dan sudah berstatus SO Done."
                ], 422);
            }

            if ($isBundle) {
                if ($product->product_status === 'sale') {
                    return response()->json([
                        'status' => false,
                        'message' => 'Gagal: Bundle ini sudah berstatus Terjual (Sale).'
                    ], 422);
                }
            } else {
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
                        'message' => 'Gagal: Status produk "' . $product->new_status_product . '" dilarang masuk rak.'
                    ], 422);
                }

                $quality = $product->new_quality;
                if (is_string($quality)) {
                    $quality = json_decode($quality, true);
                }

                if (is_array($quality)) {
                    if (empty($quality['lolos'])) {
                        $failReason = 'Kualitas tidak memenuhi syarat (Bukan Lolos)';

                        if (!empty($quality['abnormal'])) {
                            $failReason = "Abnormal: " . $quality['abnormal'];
                        } elseif (!empty($quality['damaged'])) {
                            $failReason = "Damaged: " . $quality['damaged'];
                        } elseif (!empty($quality['non'])) {
                            $failReason = "Non: " . $quality['non'];
                        }

                        return response()->json([
                            'status' => false,
                            'message' => "Gagal: Produk tidak bisa masuk rak. Status $failReason."
                        ], 422);
                    }
                }
            }

            $productCategory = $isBundle ? strtoupper(trim($product->category ?? '')) : strtoupper(trim($product->new_category_product ?? ''));

            if (!empty($targetRack->name) && !empty($productCategory)) {
                $rackName = strtoupper(trim($targetRack->name));

                if (strpos($rackName, '-') !== false) {
                    $rackCore = substr($rackName, strpos($rackName, '-') + 1);
                } else {
                    $rackCore = $rackName;
                }

                $rackCore = preg_replace('/\s+\d+$/', '', $rackCore);
                $keywords = preg_split('/[\s,]+/', $rackCore, -1, PREG_SPLIT_NO_EMPTY);
                $isMatch = false;

                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if (!empty($cleanKeyword) && strpos($productCategory, $cleanKeyword) !== false) {
                        $isMatch = true;
                        break;
                    }
                }

                if (!$isMatch) {
                    $tipeItem = $isBundle ? 'Bundle' : 'Produk';
                    return response()->json([
                        'status' => false,
                        'message' => "Gagal: Kategori {$tipeItem} '$productCategory' tidak sesuai dengan Rak '$rackName'."
                    ], 422);
                }
            }

            if ($sourceType === 'display' || $sourceType === 'bundle') {
                $oldRackId = $product->rack_id;

                $product->update([
                    'rack_id' => $targetRack->id,
                    'is_so'   => 'done',
                    'user_so' => $user->id
                ]);

                if ($oldRackId && $oldRackId != $targetRack->id) {
                    $oldRack = Rack::find($oldRackId);
                    if ($oldRack) $this->recalculateRackTotals($oldRack);
                }
            } elseif ($sourceType === 'staging') {
                $oldStagingRackId = $product->rack_id;

                $data = $product->toArray();
                unset($data['id'], $data['created_at'], $data['updated_at']);

                $data['rack_id'] = $targetRack->id;
                $data['is_so']   = 'done';
                $data['user_so'] = $user->id;

                $newProduct = New_product::create($data);
                $product->delete();

                if ($oldStagingRackId) {
                    $oldStagingRack = Rack::find($oldStagingRackId);
                    if ($oldStagingRack) {
                        $this->recalculateRackTotals($oldStagingRack);
                    }
                }

                $product = $newProduct;
            }

            $this->recalculateRackTotals($targetRack);

            RackHistory::create([
                'user_id'      => $user->id,
                'rack_id'      => $targetRackId,
                'product_id'   => $product->id,
                'barcode'      => $isBundle ? $product->barcode_bundle : $product->new_barcode_product,
                'product_name' => $isBundle ? $product->name_bundle : $product->new_name_product,
                'action'       => 'IN',
                'source'       => $sourceType
            ]);

            DB::commit();

            $namaFinal = $isBundle ? $product->name_bundle : $product->new_name_product;
            return new ResponseResource(true, "Berhasil: Masuk Rak {$targetRack->name} & SO Done", $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function recalculateRackTotals($rack)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $stagingQuery = $rack->stagingProducts()->whereNotIn('new_status_product', $excludedStatuses);
        $inventoryQuery = $rack->newProducts()->whereNotIn('new_status_product', $excludedStatuses);
        $bundleQuery = $rack->bundles()->where('product_status', '!=', 'sale');

        $totalData = $stagingQuery->count() + $inventoryQuery->count() + $bundleQuery->count();

        $totalNewPrice = $stagingQuery->sum('new_price_product')
            + $inventoryQuery->sum('new_price_product')
            + $bundleQuery->sum('total_price_custom_bundle');

        $totalOldPrice = $stagingQuery->sum('old_price_product')
            + $inventoryQuery->sum('old_price_product')
            + $bundleQuery->sum('total_price_bundle');

        $totalDisplayPrice = $stagingQuery->sum('display_price')
            + $inventoryQuery->sum('display_price')
            + $bundleQuery->sum('total_price_custom_bundle');

        $rack->update([
            'total_data' => $totalData,
            'total_new_price_product' => (string) $totalNewPrice,
            'total_old_price_product' => (string) $totalOldPrice,
            'total_display_price_product' => (string) $totalDisplayPrice,
        ]);
    }

    public function resetSo($id)
    {
        try {
            $rack = Rack::find($id);
            if (!$rack) return response()->json(['status' => false, 'message' => 'Rak tidak ditemukan'], 404);

            $rack->update([
                'is_so' => 0,
                'user_so' => null
            ]);

            return new ResponseResource(true, 'Status SO rak di-reset', $rack);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // migrate to repair
    public function soMigrateRepairProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();

            $product = MigrateBulkyProduct::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk migrate repair tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil SO Produk: ' . $product->new_name_product, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // abnormal
    public function soAbnomalProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();
            $product = null;
            $source = '';

            $product = New_product::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $source = 'display';
            } else {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();
                if ($product) {
                    $source = 'staging';
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk Abnormal tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $quality = $product->new_quality;
            if (is_string($quality)) {
                $quality = json_decode($quality, true);
            }

            if (!isset($quality['abnormal'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ini bukan Abnormal.'
                ], 422);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil SO {$source}: " . $product->new_name_product, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // damaged
    public function soDamagedProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();
            $product = null;
            $source = '';

            $product = New_product::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $source = 'display';
            } else {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();
                if ($product) {
                    $source = 'staging';
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk Damaged tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $quality = $product->new_quality;
            if (is_string($quality)) {
                $quality = json_decode($quality, true);
            }

            if (!isset($quality['damaged'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ini bukan Damaged.'
                ], 422);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil SO {$source}: " . $product->new_name_product, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // non
    public function soNonProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $barcode = $request->barcode;
            $user = Auth::user();
            $product = null;
            $source = '';

            $product = New_product::where(function ($q) use ($barcode) {
                $q->where('new_barcode_product', $barcode)
                    ->orWhere('old_barcode_product', $barcode);
            })->first();

            if ($product) {
                $source = 'display';
            } else {
                $product = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();
                if ($product) {
                    $source = 'staging';
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk Non tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            if ($product->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ' . $product->new_name_product . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $quality = $product->new_quality;
            if (is_string($quality)) {
                $quality = json_decode($quality, true);
            }

            if (!isset($quality['non'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Produk ini bukan Non.'
                ], 422);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil SO {$source}: " . $product->new_name_product, $product);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // b2b
    public function soB2BDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $code_document = $request->code_document;
            $user = Auth::user();

            $document = BulkyDocument::where(function ($q) use ($code_document) {
                $q->where('code_document_bulky', $code_document);
            })->first();

            if (!$document) {
                return response()->json([
                    'status' => false,
                    'message' => 'Dokumen B2B tidak ditemukan dengan code: ' . $code_document
                ], 404);
            }

            if ($document->is_so === 'done') {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Dokumen ' . $code_document . ' sudah di SO sebelumnya.'
                ], 422);
            }

            $document->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil SO Dokumen: ' . $document->code_document_bulky, $document);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function migrateSpecificNewToStaging()
    {
        $targetBarcodes = [
            '299L2530qTBgq',
            '299L2530IDAtI',
            '13SE25119MJjm3'
        ];

        $movedCount = 0;
        $deletedDuplicateCount = 0;

        try {
            DB::beginTransaction();

            $query = New_product::whereIn('new_barcode_product', $targetBarcodes);

            $query->chunk(100, function ($products) use (&$movedCount, &$deletedDuplicateCount) {
                foreach ($products as $product) {
                    $barcode = $product->new_barcode_product;

                    $existsInStaging = StagingProduct::where(function ($q) use ($barcode) {
                        $q->where('new_barcode_product', $barcode)
                            ->orWhere('old_barcode_product', $barcode);
                    })->exists();

                    if ($existsInStaging) {
                        $product->delete();
                        $deletedDuplicateCount++;
                    } else {
                        $dataToMove = $product->toArray();

                        unset($dataToMove['id']);
                        unset($dataToMove['created_at']);
                        unset($dataToMove['updated_at']);

                        if (isset($dataToMove['new_quality']) && is_array($dataToMove['new_quality'])) {
                            $dataToMove['new_quality'] = json_encode($dataToMove['new_quality']);
                        }

                        if (isset($dataToMove['actual_new_quality']) && is_array($dataToMove['actual_new_quality'])) {
                            $dataToMove['actual_new_quality'] = json_encode($dataToMove['actual_new_quality']);
                        }

                        StagingProduct::create($dataToMove);

                        $product->delete();
                        $movedCount++;
                    }
                }
            });

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Migrasi Hardcoded Selesai.',
                'data' => [
                    'total_moved' => $movedCount,
                    'total_deleted_duplicates' => $deletedDuplicateCount,
                    'total_processed' => $movedCount + $deletedDuplicateCount
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
