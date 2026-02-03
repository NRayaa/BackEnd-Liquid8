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

    public function migrateSpecificNewToStaging()
    {
        $targetBarcodes = [
            'L100FAZV0R3', 'L100FA3AHDH', 'L147FJBGEYZ', 'L100FAPNBQO', 'L100FMWMQ2V', 
            'L21FMAF06C', 'L21FJW63BE', 'L155FO7S5Q4', 'L100FAPGORV', 'L100FAW36RT', 
            'L21FMCFO7T', 'L21FJ5OJZK', 'L21FJUPW0T', 'L21FJF0CKK', 'L147FJ22QA6', 
            'L126FJV0OCB', 'L111FJ7GA5L', 'L72FJHPVXL', 'L114FJJ7L1W', 'L61FOXH172', 
            'L30FO9G1EN', '296L2530zvedH', '28AWS2561HcRFE', 'L100FAASQPN', 'L100FA7HC2L', 
            'L100FA0O9JF', 'L21FM95MYY', '296L2555ET6XO', 'L100FMXXQZF', 'L71FJ05TWF', 
            '27AWS251133Ib0i', 'L100FATY76K', 'L100FAEAAIV', 'L21FMF5MU7', 'L71FJ4A8G5', 
            '277L25159BXC3I', 'L100FAYQ57U', 'L100FAKLGP0', 'L71FJDXVGO', 'L71FJ2Q5B5', 
            'L100FAWRUMA', 'L21FMO0C7X', 'L21FJNVHEJ', 'L147FJIIZ33', 'L111FJDLSWZ', 
            '28AWS25111QM9j1', 'L100FACWYUD', '27AWS25111FURkp', 'L120FJUVJB1', 'L72FJHFC6D', 
            'L72FJEF82X', 'L137FJLS2W9', 'L120FJ14EMA', 'L120FJZ6HHP', 'L72FJ7E8UL', 
            'L111FJFYUNM', 'L137FJTAGDU', 'L111FJIBZTJ', 'L111FJE298N', '28AWS2564nwkDd', 
            'L21FJXBU1O', '304L25138FbdM2', '18AWS2548ntohu', '16AWS2465ap4Eg', 'L111FJE8MWF', 
            'L114FJZHTA8', '296L2555INaPP', '304L25138VobHu', '303L251516QAqc', 'L147FJCJKP8', 
            'L147FJZB2H6', 'L61FOPFEH6', 'L55FOJNNFM', 'L30FO0OYL6', '18AWS2578ThVe-', 
            '16AWS24920KcXx', 'L100FAHMGAE', 'L21FJXY7PU', 'L21FJJZWIR', 'L71FJM33EN', 
            'L147FJS5C54', 'L113FJ32Y74', 'L147FJGRR5J', 'L126FJ5XNR6', 'L146FJSVT9P', 
            'L21FJD8RMJ', 'L21FJ1BTBF', 'L148FJJRV6F', 'L100FABW0IW', 'L100FAHYDYB', 
            'L2FMO80NG', 'L21FJIFTK1', 'L55FOWB97V', 'L55FOJF6PO', 'L159FDU8NG2', 
            'L113FJIRV1L', 'L113FJFJL2F', '14AWS2447Zh9Lz', 'L21FJQ2B63', 'L21FJU5PWW', 
            'L21FJ4Y8H7', 'L21FJ7Y5T6', 'L21FJKN3UB', 'L87FJH540L', 'L29FJGW6GB', 
            'L87FJ6CK8N', 'L71FJSCA3L', 'L71FJXE88U', 'L147FJDB7R7', 'L147FJ0NL8B', 
            'L146FJ12BVP', 'L126FJ3FUPC', 'L147FJXOYP5', 'L146FJ82MXR', 'L111FJB5WV0', 
            'L21FJZ5VJH', 'L147FJTWDME', 'L146FJCH8DR', 'L147FJZO6X9', 'L147FJGM3LG', 
            'L147FJ6CPKS', 'L147FJAUOML', 'L21FJG8L65', 'L21FJGBG5I', 'L21FJWRPQ0', 
            '11SE2555W4mdD', '27AWS2582yovDg', 'L21FJ39S28', '28AWS25114Fgzt5', '28AWS25114NkP1d', 
            'L146FJKIKQL', 'L147FJI2QB1', 'L146FJ5V0MM', 'L146FJHVMP9', 'L146FJ7K5N8', 
            'L146FJT5LD5', 'L146FJWNI8E', 'L147FJCYHY8', 'L146FJHS2ZG', 'L146FJ40DVL', 
            'L146FJS2ON7', 'L147FJWV2EK', 'L72FJFN6HI', 'L114FJI041V', 'L138FJZFS34', 
            '28AWS25114eM2fe', 'L126FJCF7VU', 'L126FJ4H8MZ', 'L126FJGJWEB', 'L126FJI2LLL', 
            'L126FJ0IK0D', 'L126FJB51SV', 'L126FJHSWQC', 'L126FJ23B5E', 'L126FJEHKA2', 
            '16AWS2497csj-P', '16AWS246111JLZ', 'L126FJIS66P', 'L126FJ90LIH', 'L126FJOX490', 
            'L126FJSMJJX', 'L126FJQG00P', 'L126FJXLGSC', 'L126FJFQGH2', '306L2530nO6ak', 
            'L21FF0XRKR', 'L126FJRYSXC', 'L126FJC28D6', 'L126FJ78KK1', 'L126FJ7UGGD', 
            'L126FJGP5QC', 'L126FJ3Q1CW', 'L126FJ79NRZ', 'L126FJ2LXMH', 'L148FJFFU41', 
            'L55FOL11P7', 'L55FO3FEZT', 'L61FOMSG6D', 'L55FO26PKZ', 'L55FOHLR5Y', 
            'L55FOZGRGR', 'L61FOC2U91', 'L55FO0PG1H', 'L55FOL3N4E', 'L30FOUQ1YL', 
            'L137FJQZ6GF', 'L111FJVM5AK', '12SE251198oeFl', '303L25158guuky', '304L25151ay1S9', 
            '304L25151lG-dN', '304L25138atpcH', '304L25151-ypoR', '304L25119sXwAV', '304L2511905itz', 
            '304L25138Y7eW6', '304L25119Zeh6Y', '304L2555uYqBZ', '304L25159vKi0C', '304L25551Qbpl', 
            'L21FAJ63AA', 'L21FMT00Z3', 'L100FMUHSXV', '269L25658K682', 'L147FJC1KZO', 
            'L138FJ8WUJW', 'L55FO5YXHW', '303L2530vZmaZ', '303L2530yClvA', '304L25119oRAlj', 
            '304L2555DST-M', '304L2561JG908', '304L251385Truq', '304L25119QIWKI', '304L2555cjcLP', 
            '304L25159ARgXe', 'L120FJEUWRQ', 'L137FJ122KR', 'L137FJ2VJWV', 'L137FJW1PS0', 
            'L111FJOQV77', '28AWS25117txJ7d', 'L147FJWIQNE', 'L147FJ4Y58W', 'L148FJZDHQ9', 
            'L147FJI2WJ6', 'L148FJI532Y', 'L55FOGDZ5N', '28AWS2564vUdtB', 'L100FFL59GB', 
            'L126FJXRV2F', 'L126FJUP252', 'L126FJGOA34', 'L126FJ4EVR5', 'L126FJNX29Z', 
            'L126FJO4JU9', 'L126FJ310O9', 'L126FJDMAPA', 'L126FJIMT0Q', 'L126FJAV5SG', 
            'L126FJRBZRP', '12AWS2448gyaS4', 'L126FJ619AK', 'L146FJMIA61', 'L126FJWILSK', 
            'L21FJJS2EF', 'L21FJB54VK', 'L21FJ9JTSZ', 'L21FJ4CBTV', 'L21FJTQ32Y', 
            'L21FJZI6XO', 'L21FJOE5E2', 'L21FJ7QAU0', 'L111FJELJ89', 'L137FJQI39P', 
            'L114FJB98Z7', 'L148FJNO620', 'L113FJPZB1Z', 'L147FJ9A98I', 'LQD43816', 
            'L147FJWRS4Q', 'L148FJ5AJB5', 'L148FJ3N9BS', 'L147FJB34V9', 'L147FJ7C27L', 
            '12SE2561zcNd7', '12SE25119WBEpr', '12SE25138DlJvB', '28AWS2572DUDDl', 'L21FJMMVF0', 
            'L21FJTQ6DB', 'L21FJPRWJQ', 'L21FJ6MU8R', 'L126FJBTW79', 'L126FJ8HE8F', 
            'L126FJEO9KZ', 'L126FJ8IZPW', 'L100FABMUSW', 'L100FM0ZMKW', 'L137FJM44TK', 
            'L114FJHPGED', 'L120FJS596A', 'L72FJNXZ7M', 'L147FJ6WYXB', 'L138FJB0Z4G', 
            'L72FJJM8HX', 'L114FJ5LVXS', 'L114FJKFKUT', '268L25119W4u0c', '28AWS25115OTt3Z', 
            '28AWS25117hMFg5', '28AWS25111T3Jrx', 'L30BD8E34Y', 'L30BDY3IMB', 'L30BDHKA5W', 
            '4AWS2447rWhMr', 'L120FJ40K2M', 'L120FJXEQ1X', 'L120FJ3C5ET', 'L72FJM595V', 
            'L138FJKDJ0N', 'L137FJBGI9E', 'L137FJP7K67', '257L251195ROiz', 'L72FJXS66P', 
            'L137FJ852BW', 'L100FAYEHPS', 'L100FAJ4UB9', 'L100FM0AYGV', 'L100FMAY65Y', 
            'L21FJ2IGZF', 'L21FJYJG3W', 'L114FJZDKCW', 'L137FJ2GUJM', 'L72FJK0CC2', 
            'L72FJJUKFM', '28AWS251159j8Na', 'L100FABU47D', 'L147FJEXKOZ', 'L138FJ9CIVK', 
            '12AWS2465OfMd-', 'L126FJVT8SP', 'L155FOHA8YF', '28AWS25113YbNoH', 'L21FMFG0XO', 
            'L21FJVR2DM', '17AWS2560biqk3', 'L72FJX0DC6', 'L111FJU67OR', 'L111FJKML7J', 
            'L111FJSR9J5', 'L120FJ4G1W9', 'L114FJ7B9MM', 'L114FJ6I3Q1', 'L120FJWBCJX', 
            'L147FJ8A95T', 'L100FA3RAUM', 'L2FJLKAL9', 'L146FJ9OBEL', 'L100FAFMJCU', 
            'L61FJLXXBN', 'L65FJAEQOV', 'L100FAREMMD', 'L100FAZKMS3', 'L100FAB1DR5', 
            'L100FAGRXVF', 'L100FAITNJQ', 'L100FA6A57U', 'L100FABEGGE', 'L100FASW9T1', 
            '14AWS2494BE0Yv', 'L113FJXD2UG', 'L147FJWFZWR', 'L100FMM8LBT', 'L87FJUUMK4', 
            'L87FJNO8MV', 'L83FJGX6C2', 'L83FJI0Y6K', 'L21FJID8GM', 'L21FJP1TC1', 
            'L21FJMMRPI', 'L21FJK7NTV', 'L21FJ4TVNZ', 'L21FJ5ABA0', 'L21FJX0VYB', 
            'L21FJMTPLP', 'L21FJD7WM8', 'L87FJVVYVO', 'L87FJOFMGN', 'L29FJ6ODGQ', 
            'L29FJY2XQ9', 'L87FJXQUNS', 'L29FJS1ILF', 'L29FJNZR2B', 'L87FJM50RM', 
            'L29FJ1D9UU', 'L87FJNDQ50', 'L87FJBO9M0', 'L87FJF6YMN', 'L87FJEPP2S', 
            'L87FJUOADF', 'L29FJLZPL3', 'L87FJPASAT', 'L29FJAP1PB', 'L87FJO9KVA', 
            'L29FJZBH8S', 'L87FJXZ8NF', 'L87FJ2TLU7', 'L29FJ9YME7', 'L87FJDL4ZO', 
            'L29FJIQ8NX', 'L29FJKOX4C', '302L25158SBABt', '302L25158Fs9fv', 'L114FJBKWA4', 
            'L114FJO8THS', 'L138FJFUV55', 'L113FJ70P57', 'L113FJAWIJ4', 'L113FJZ1A4H', 
            'L146FJ04FUP', 'L146FJI72JI', 'L146FJ5W3QD', 'L146FJJ893F', 'L113FJZNXYE', 
            'L113FJ1HOLL', 'L113FJIOSV2', 'L113FJU1TIQ', 'L113FJ8TZW9', 'L113FJ8ZAIM', 
            'L83FJ603E9', 'L87FJAE3MY', 'L29FJAJ2DH', 'L83FJQ0NMD', 'L29FJCJF9D', 
            'L87FJE80GS', 'L87FJ0XE3K', '259L25100M5rZi', 'L100FAZ2OCY', 'L83FJBB6UZ', 
            'L83FJNSUQT', 'L103FJ9U8UF', 'L83FJ0J38N', 'L83FJH0DI5', 'L83FJ5L4IP', 
            'L83FJAV5IX', 'L83FJE77EI', 'L83FJRWBT2', 'L83FJOTVU0', 'L83FJJ90V6', 
            'L83FJCOPWS', 'L83FJ6EMGT', 'L83FJIH1ZN', 'L83FJK7LSH', 'L83FJ37IYL', 
            'L83FJOCVZ2', 'L83FJWKU4Y', 'L83FJ55I6T', 'L83FJQUVX2', 'L83FJX0PMQ', 
            'L83FJGPL8A', '11SE25138QMa3Z', 'L103FJOXX2L', 'L103FJPTD9O', '28AWS25111qOGHz', 
            'L103FJZ1IX3', 'L83FJRRUHG', 'L87FJAR8PT', 'L87FJ178YW', '4AWS2457vOCCW', 
            'L87FJJ0L2O', '2-AWS25723Erak', 'L83FJJ3KEW', 'L83FJA3ELH', 'L83FJZ5GAE', 
            'L103FJ8YH5O', 'L103FJ13332', 'L83TJX2X6M', 'L103FJ0KXP0', 'L103FJ9D4DG', 
            'L103FJLJI3C', 'L83FJBER34', 'L103FJ1NJOV', 'L83FJJEIK4', 'L137OJZG8GM', 
            '5SE2596q3EE1', 'L120OJ8VB6X', '303L25159bWK5A', '5SE2591BnckA', '5SE25619wU2p', 
            '5SE2582vjRoX', '5SE2591Msqxn', 'L126OJBXBTH', '30BNRHB5X', 'L100OAXM49K', 
            'L100OM8UHQ4', '297L256139Cwe', 'L21OMFRN02', 'L100OMCTUYW', 'L100OMP9P9Q', 
            '5SE25812N1mu', 'L126OJH465T', 'L126OJ9T5AE', 'L126OJ761DT', 'L126OJVTGPT', 
            '5SE2593ZUvZW', '5SE2561k0usZ', 'L21OML4XNW', 'L100OMI5453', 'L21OMJY49A', 
            'L126OJ9Z35M', '5SE25119nFKj7', 'L100OMGJQDW', 'L100OM4D9B4', '18AWS25938DNTW', 
            'L21OMHMW3A', 'L126OJYBCL7', '11SE2561ki9fd', '11AWS24547kdr2', '4SE2554GuVzL', 
            '4SE25548TKP5', '4SE2548wWu4x', 'L148OJE7U0S', 'L146OJPO0CJ', 'L146OJ8J7LO', 
            'L148OJ7P2NI', 'L126OJ9GXK5', 'L126OJ4T4L0', 'L138OJ6ATNB', 'L65OJ0SQ7K', 
            'L147OJ1ZL2P', 'L147OJZS9MX', 'L113OJ67H0Q', 'L138OJ0JNL9', 'L148OJ1Q93E', 
            '5AWS2457xJXnY', 'L21FJWAZRD', 'L21FJUVRZ0', 'L21FJGUN8Q', 'L21FJTW868', 
            'L21FJVT9SB', 'L21FJMRY2T', 'L126OJO8JPQ', 'L126OJ112X8', 'L126OJFR6AW', 
            '4SE2565Zva9Y', 'L148OJXZJNW', 'L126OJSIF9E', '2-AWS251159h9-I', '12SE25163LUOx4', 
            '3SE2417CBFmT', 'L159FORNDOT', 'L30FNZO6J3', '17AWS2591h8HHH', 'L147OJ3QRV1', 
            'L147OJOSSH9', 'L147OJE9L8N', 'L100OMKX6LE', 'L126OJ60B78', 'L126OJ1D11F', 
            'L147OJZ6X4W', '301L2530rzzG0', 'L87OJBYGSW', 'L126OJ1HIHM', 'L126OJ5QELK', 
            '13SE2561e5zWJ', '7SE25113NY1Cg', '13SE25151eVRxX', '11AWS2465gdynt', 'L126OJGWG98', 
            'L126OJJP1XE', 'L126OJATJF3', 'L126OJDKGC1', 'L126OJO1UJD', 'L126OJB9AQV', 
            'L126OJALCYA', 'L146OJM5G8W', 'L146OJ10E4R', 'L146OJ4RS42', 'L146OJYTYXM', 
            'L126OJSYIWN', 'L114OJP2482', 'L72OJVRDWX', 'L126OJZTG4N', 'L126OJG5ST5', 
            '28AWS25111yM5Cn', 'L114OJD4M9K', 'L21OMYQ86B', '25AWS25116anHr3', 'L100OMXF5EM', 
            'L100OMS7CVA', 'L100OM0HZOK', 'L126OJCYOWE', 'L126OJ6UO3U', 'L114OJ0XQOO', 
            'L100OMYTHNR', 'L72OJGH6SC', 'L126OJU5IAQ', 'L72OJIZ7XO', 'L21OJUXM5N', 
            '28AWS25100jxHW0', 'L126OJMJ0OG', 'L126OJVM9ME', 'L30ON7CB1N', 'L126OJE0TW6', 
            'L126OJBLSR7', 'L126OJ9EPGR', 'L126OJWWMQV', 'L126OJYDK8R', 'L126OJM0G2N', 
            'L126OJHCRFF', 'L126OJ3SMYT', 'L146OJDMNUH', 'L126OJ0QMNI', 'L126OJJ36L5', 
            'L126OJ5W2VD', 'L126OJIEY2X', '20AWS25990IwMv', 'L146OJPEEBQ', 'L21OM19WDV', 
            'L126OJDL8MT', 'L126OJCS6EC', 'L111OJM788F', 'L2EJ1XCRK', '15AWS2499HdiSs', 
            'L21EMNY7VB', 'L138EOSC37I', 'L30ENRRW2H', 'L2EJGAOUZ', 'L2EJPU5MQ', 
            'L2EJM4ZE3', 'L2EJKM24C', 'L2EJJ93F2', 'L2EJIXT1N', 'L2EJYM6UH', '13AWS2417ye3gm', 
            'L148OJR9AYD', 'L100OASZ2AS', 'L100OMUESY1', 'L155EOGN0I7', '8124-002757', 
            'L138EOA4W1U', '1-AWS251108qlt2', '18AWS2565RCeaL', '1H1AWS24173', '297L25151dmRN6', 
            '277L25158-7YFA', '297L25151gt3Cp', '297L25151jcDgy', '299L25163HmH3w', '20AWS2572X6kiF', 
            'L159EONYZU3', '2AWS2448vAOCZ', 'L100EMJ0P1V', 'L100EMWX4LV', '250L2599ExVYN', 
            '304L25119BAR8u', '308L2661Iunpx', '304L25119bfbFp', '16AWS2482eY5-Y', '21AWS25115ErJA-', 
            '1-AWS25642RI9z', '25AWS25100ZRKvb', '304L25159kKu5W', '25AWS25113yDrJO', '302L2530n9S8Q', 
            '6AWS24554uNlA', '276L252knGPe', '20AWS2599e6z28', '19AWS251107N3Qb', 'L100EM9LX4L', 
            '28AWS25111I3bfc', 'L21EM6GZ6G', '8024-001769', '8524-002830', 'LEJO8KY4', 
            'LQD96175', 'LQD90030', 'LQD13105', '175L24124', 'L100EMYF6ER', 'L100EMEDSMA', 
            'L100EMDFX9P', '21AWS2593QBgAT', '28AWS25117EzDb8', '21AWS25111bDMrF', 'L126EJGB5BO', 
            '19AWS2564BtVfX', '20AWS25116UHvf-', '1-AWS2582KmFha', 'L29EJIENVM', 'L87EJ08CJT', 
            'L29EJS7ETQ', 'L21EM70K1H', 'L100EMB0AAE', '12AWS2498AdTe8', 'L100EMUC76K', 
            'L29EJDSFUJ', 'L29EJJBIAZ', 'L29EJZXBNV', 'L29EJ1F8PC', 'L126EJ2GCWK', 
            '16AWS2482OUueo', 'L100EMTW4FW', 'L126EJLJ71O', 'L126EJGO05F', 'L126EJNT1RO', 
            'L126EJZ52O5', '28AWS2572kVh7A', 'L126EJY0NMQ', 'L126EJV5VNY', 'L87EJ9WMOP', 
            'L87EJKOVXG', 'L87EJYOTPQ', 'L87EJFVXIM', 'L87EJPCHUH', 'L87EJZIJ96', 
            'L29EJ7S8W7', 'L126EJJN6V8', 'L126EJHC6SR', '257L25115YwBtU', 'L87EJZN62V', 
            'L87EJTTSHF', 'L87EJEF9ZU', 'L114EJL7HOA', '19AWS2597rN-qm', 'L126EJKMZPM', 
            'L100EA08QTY', 'L100EAXNS9B', 'L100EAXLD4Q', 'L100EAZBZB9', 'L126EJ9UB0Y', 
            '299L2530qTBgq', '299L2530IDAtI', '13SE25119MJjm3'
        ];

        $movedCount = 0;
        $deletedDuplicateCount = 0;

        try {
            DB::beginTransaction();

            $query = New_product::whereIn('new_barcode_product', $targetBarcodes);
            
            $query->chunk(100, function ($products) use (&$movedCount, &$deletedDuplicateCount) {
                foreach ($products as $product) {
                    $barcode = $product->new_barcode_product;

                    $existsInStaging = StagingProduct::where(function($q) use ($barcode) {
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
