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
            } else {
                $product = Bundle::where('barcode_bundle', $barcode)->first();
                if ($product) {
                    $source = 'bundle';
                }
            }

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produk Inventory (Display/Bundle) tidak ditemukan dengan barcode: ' . $barcode
                ], 404);
            }

            $product->update([
                'is_so' => 'done',
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, "Berhasil SO {$source}: " . ($product->new_name_product ?? $product->name_bundle), $product);
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


            $rack->update([
                'is_so' => 1,
                'user_so' => $user->id
            ]);

            DB::commit();

            return new ResponseResource(true, 'Berhasil melakukan SO pada rak: ' . $rack->name, $rack);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
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
