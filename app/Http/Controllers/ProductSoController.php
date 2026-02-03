<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BulkyDocument;
use App\Models\Bundle;
use App\Models\MigrateBulkyProduct;
use App\Models\New_product;
use App\Models\Rack;
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

            // Cek di New_product (Display/Inventory) terlebih dahulu
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

                $product->update([
                    'is_so' => 'done',
                    'user_so' => $user->id
                ]);
            } else {
                // Jika tidak ada di Display, Cek di StagingProduct
                $stagingProduct = StagingProduct::where(function ($q) use ($barcode) {
                    $q->where('new_barcode_product', $barcode)
                        ->orWhere('old_barcode_product', $barcode);
                })->first();

                if ($stagingProduct) {
                    // Skenario B: Produk dari Staging, Pindahkan ke Display
                    $source = 'staging_moved_to_display';

                    // Cek apakah di staging sudah di SO (opsional, tergantung aturan, tapi amannya dicek)
                    if ($stagingProduct->is_so === 'done') {
                    }

                    // Siapkan data untuk dipindah ke New_product
                    $productData = $stagingProduct->toArray();

                    // Hapus ID lama agar dibuatkan ID baru auto-increment di tabel tujuan
                    unset($productData['id']);
                    unset($productData['created_at']);
                    unset($productData['updated_at']);

                    // Set status SO dan User
                    $productData['is_so'] = 'done';
                    $productData['user_so'] = $user->id;

                    // Opsional: Jika pindah ke display, biasanya rack_id diset null atau disesuaikan
                    // $productData['rack_id'] = null; 

                    // Insert ke tabel New_product
                    $product = New_product::create($productData);

                    // Hapus data dari StagingProduct
                    $stagingProduct->delete();
                } else {
                    // Jika tidak ada di Staging, Cek Bundle (Logic lama)
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

            // Jika setelah mencari di ketiga tempat tetap tidak ditemukan
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
                'user_so' => $user->id
            ]);

            New_product::where('rack_id', $rack->id)->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            StagingProduct::where('rack_id', $rack->id)->update([
                'is_so' => 'done',
                'user_so' => $user->id
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
                'user_so' => $user->id
            ]);

            $updateData = [
                'is_so' => 'done',
                'user_so' => $user->id
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
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk tidak ditemukan.'
                ], 404);
            }

            if ($product->is_so === 'done' && $product->rack_id == $targetRackId) {
                return response()->json([
                    'status' => false,
                    'message' => "Gagal: Produk ini sudah berada di Rak {$targetRack->name} dan sudah berstatus SO Done."
                ], 422);
            }

            $forbiddenStatuses = ['dump', 'sale', 'migrate', 'repair', 'scrap_qcd'];
            if (in_array($product->new_status_product, $forbiddenStatuses)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal: Status produk "' . $product->new_status_product . '" dilarang masuk rak.'
                ], 422);
            }

            if (!empty($targetRack->name) && !empty($product->new_category_product)) {
                $rackName = strtoupper(trim($targetRack->name));
                $productCategory = strtoupper(trim($product->new_category_product));

                if (strpos($rackName, '-') !== false) {
                    $rackCore = substr($rackName, strpos($rackName, '-') + 1);
                } else {
                    $rackCore = $rackName;
                }

                $rackCore = preg_replace('/\s+\d+$/', '', $rackCore);

                $keywords = explode(',', $rackCore);
                $isMatch = false;

                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if (!empty($cleanKeyword) && strpos($productCategory, $cleanKeyword) !== false) {
                        $isMatch = true;
                        break;
                    }
                }

                if (!$isMatch) {
                    return response()->json([
                        'status' => false,
                        'message' => "Gagal: Kategori Produk '$productCategory' tidak sesuai dengan Rak '$rackName'."
                    ], 422);
                }
            }

            if ($sourceType === 'display') {

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

            DB::commit();

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
}
