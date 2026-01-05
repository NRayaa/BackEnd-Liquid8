<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Mail\TestEmail;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Mail\AdminNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\Product_Bundle;
use App\Models\ProductDefect;
use App\Models\Sale;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RiwayatCheckController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');

        $riwayats = RiwayatCheck::select('id', 'code_document', 'base_document', 'total_data', 'total_data_in', 'status_approve', 'created_at')
            ->latest()->where(function ($search) use ($query) {
                $search->where('code_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%');
            })->paginate(50);
        return new ResponseResource(true, "list riwayat", $riwayats);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            $resource = new ResponseResource(false, "User tidak dikenali", null);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'code_document' => 'required|unique:riwayat_checks,code_document',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = Document::where('code_document', $request['code_document'])->firstOrFail();

        if ($document->total_column_in_document == 0) {
            return response()->json(['error' => 'Total data di document tidak boleh 0'], 422);
        }

        DB::beginTransaction();

        try {
            $totalLolos = $totalDamaged = $totalAbnormal = 0;
            $totalData = 0;

            // Proses data dengan chunking untuk menghindari penggunaan memori yang tinggi
            ProductApprove::where('code_document', $request['code_document'])
                ->chunk(100, function ($products) use (&$totalLolos, &$totalDamaged, &$totalAbnormal, &$totalData) {
                    foreach ($products as $product) {
                        $newQualityData = json_decode($product->new_quality, true);

                        if (is_array($newQualityData)) {
                            $totalLolos += !empty($newQualityData['lolos']) ? 1 : 0;
                            $totalDamaged += !empty($newQualityData['damaged']) ? 1 : 0;
                            $totalAbnormal += !empty($newQualityData['abnormal']) ? 1 : 0;
                        }
                    }
                    $totalData += count($products);
                });

            // Menghitung harga produk dengan chunking
            $priceProductOld = Product_old::where('code_document', $request['code_document'])
                ->sum('old_price_product');

            $priceProductApprove = ProductApprove::where('code_document', $request['code_document'])
                ->sum('old_price_product');

            $totalPrice = $priceProductOld + $priceProductApprove;

            $productDiscrepancy = Product_old::where('code_document', $request['code_document'])
                ->count();

            $riwayat_check = RiwayatCheck::create([
                'user_id' => $user->id,
                'code_document' => $request['code_document'],
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => $totalData,
                'total_data_lolos' => $totalLolos,
                'total_data_damaged' => $totalDamaged,
                'total_data_abnormal' => $totalAbnormal,
                'total_discrepancy' => $document->total_column_in_document - $totalData,
                'status_approve' => 'pending',

                // persentase
                'precentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalData / $document->total_column_in_document) * 100,
                'percentage_lolos' => ($totalLolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($totalDamaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($totalAbnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => ($productDiscrepancy / $document->total_column_in_document) * 100,
                'total_price' => $totalPrice
            ]);

            $code_document = Document::where('code_document', $request['code_document'])->first();
            $code_document->update(['status_document' => 'in progress']);

            $keterangan = Notification::create([
                'user_id' => $user->id,
                'notification_name' => 'Butuh approvement untuk List Product',
                'role' => 'Spv',
                'read_at' => Carbon::now('Asia/Jakarta'),
                'riwayat_check_id' => $riwayat_check->id,
                'repair_id' => null
            ]);

            logUserAction($request, $request->user(), "inbound/check_product/multi_check", "Done check all" . $request['code_document'] . "->" . $user->id);

            DB::commit();

            return new ResponseResource(true, "Data berhasil ditambah", [
                $riwayat_check,
                $keterangan
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $resource = new ResponseResource(false, "Data gagal ditambahkan, terjadi kesalahan pada server : " . $e->getMessage(), null);
            return $resource->response()->setStatusCode(500);
        }
    }

    public function show(RiwayatCheck $history)
    {
        $getProduct = New_product::where('code_document', $history->code_document)
            ->selectRaw("new_category_product, new_tag_product, COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product")->cursor();

        $productCategoryCount = $getProduct->filter(function ($product) {
            return $product->new_category_product !== null;
        })->count();

        $productColorCount = $getProduct->filter(function ($product) {
            return $product->new_tag_product !== null;
        })->count();

        //new product ganti
        $totalOldPriceDamaged = 0;
        if ($history->status_file === 1) {
            $getProductDamaged = ProductDefect::where('riwayat_check_id', $history->id)
                ->where('new_barcode_product', '!=', null)
                ->where('type', 'damaged')->get();
            $totalOldPriceDamaged = $getProductDamaged->sum('old_price_product');
            $totalPercentageDamaged = $history->total_price != 0
                ? ($totalOldPriceDamaged / $history->total_price) * 100
                : 0;
            $totalPercentageDamaged = round($totalPercentageDamaged, 2);
        } else {
            $getProductDamaged = New_product::where('code_document', $history->code_document)
                ->whereNot('new_status_product', 'sale')
                ->where('actual_new_quality->damaged', '!=', null)
                ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
                ->cursor();
            foreach ($getProductDamaged as $product) {
                $totalOldPriceDamaged += $product->actual_old_price_product;
            }

            $totalPercentageDamaged = $history->total_price != 0
                ? ($totalOldPriceDamaged / $history->total_price) * 100
                : 0;

            $totalPercentageDamaged = round($totalPercentageDamaged, 2);
        }


        $totalOldPriceLolos = 0;
        $getProductLolos = New_product::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();

        foreach ($getProductLolos as $product) {
            $lolosValue = json_decode($product->actual_new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalOldPriceLolos += $product->actual_old_price_product;
            }
        }

        //ganti
        $totalOldPriceAbnormal = 0;
        if ($history->status_file === 1) {
            $getProductAbnormal = ProductDefect::where('riwayat_check_id', $history->id)
                ->where('new_barcode_product', '!=', null)
                ->where('type', 'abnormal')->get();
            $totalOldPriceAbnormal = $getProductAbnormal->sum('old_price_product');
            $totalPercentageAbnormal = $history->total_price != 0
                ? ($totalOldPriceAbnormal / $history->total_price) * 100
                : 0;
            $totalPercentageAbnormal = round($totalPercentageAbnormal, 2);
        } else {
            $getProductAbnormal = New_product::where('code_document', $history->code_document)
                ->whereNot('new_status_product', 'sale')
                ->where('actual_new_quality->abnormal', '!=', null)
                ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
                ->cursor();

            foreach ($getProductAbnormal as $product) {
                $abnormalValue = json_decode($product->actual_new_quality)->abnormal ?? null;
                if ($abnormalValue !== null) {
                    $totalOldPriceAbnormal += $product->actual_old_price_product;
                }
            }

            $totalPercentageAbnormal = $history->total_price != 0
                ? ($totalOldPriceAbnormal / $history->total_price) * 100
                : 0;
            $totalPercentageAbnormal = round($totalPercentageAbnormal, 2);
        }

        //staging 
        $totalPriceDamagedStg = 0;
        $getProductDamagedStg = StagingProduct::where('code_document', $history->code_document)
            ->where('actual_new_quality->damaged', '!=', null)
            ->whereNot('new_status_product', 'sale')
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();
        foreach ($getProductDamagedStg as $product) {
            $totalPriceDamagedStg += $product->actual_old_price_product;
        }

        $totalPercentageDamagedStg = $history->total_price != 0
            ? ($totalPriceDamagedStg / $history->total_price) * 100
            : 0;

        $totalPercentageDamagedStg = round($totalPercentageDamagedStg, 2);

        $totalPriceLolosStg = 0;
        $getProductLolosStg = StagingProduct::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();

        foreach ($getProductLolosStg as $product) {
            $lolosValue = json_decode($product->actual_new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalPriceLolosStg += $product->actual_old_price_product;
            }
        }

        $totalPercentageLolosStg = $history->total_price != 0
            ? ($totalPriceLolosStg / $history->total_price) * 100
            : 0;
        $totalPercentageLolosStg = round($totalPercentageLolosStg, 2);

        $totalPriceAbnormalStg = 0;
        $getProductAbnormalStg = StagingProduct::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();

        foreach ($getProductAbnormalStg as $product) {
            $abnormalValue = json_decode($product->actual_new_quality)->abnormal ?? null;
            if ($abnormalValue !== null) {
                $totalPriceAbnormalStg += $product->actual_old_price_product;
            }
        }

        $totalPercentageAbnormalStg = $history->total_price != 0
            ? ($totalPriceAbnormalStg / $history->total_price) * 100
            : 0;
        $totalPercentageAbnormalStg = round($totalPercentageAbnormalStg, 2);

        $totalStagings = count($getProductDamagedStg) + count($getProductLolosStg) + count($getProductAbnormalStg);

        //product approve
        $totalPriceDamagedAp = 0;
        $getProductDamagedAp = ProductApprove::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->damaged', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();
        foreach ($getProductDamagedAp as $product) {
            $totalPriceDamagedAp += $product->actual_old_price_product;
        }

        $totalPercentageDamagedAp = $history->total_price != 0
            ? ($totalPriceDamagedAp / $history->total_price) * 100
            : 0;

        $totalPercentageDamagedAp = round($totalPercentageDamagedAp, 2);

        $totalPriceLolosAp = 0;
        $getProductLolosAp = ProductApprove::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->lolos', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();

        foreach ($getProductLolosAp as $product) {
            $lolosValue = json_decode($product->actual_new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalPriceLolosAp += $product->actual_old_price_product;
            }
        }

        $totalPercentageLolosAp = $history->total_price != 0
            ? ($totalPriceLolosAp / $history->total_price) * 100
            : 0;
        $totalPercentageLolosAp = round($totalPercentageLolosAp, 2);

        $totalPriceAbnormalAp = 0;
        $getProductAbnormalAp = ProductApprove::where('code_document', $history->code_document)
            ->whereNot('new_status_product', 'sale')
            ->where('actual_new_quality->abnormal', '!=', null)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();

        foreach ($getProductAbnormalAp as $product) {
            $abnormalValue = json_decode($product->actual_new_quality)->abnormal ?? null;
            if ($abnormalValue !== null) {
                $totalPriceAbnormalAp += $product->actual_old_price_product;
            }
        }

        $totalPercentageAbnormal = $history->total_price != 0
            ? ($totalPriceAbnormalAp / $history->total_price) * 100
            : 0;
        $totalPercentageAbnormal = round($totalPercentageAbnormal, 2);

        $totalPA = count($getProductDamagedAp) + count($getProductLolosAp) + count($getProductAbnormalAp);

        //product Bundle
        $totalPriceProductBundle = 0;
        $getProductProductBundle = Product_Bundle::where('code_document', $history->code_document)
            ->selectRaw('COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product, actual_new_quality')
            ->cursor();
        foreach ($getProductProductBundle as $product) {
            $totalPriceProductBundle += $product->actual_old_price_product;
        }

        $totalPercentageProductBundle = $history->total_price != 0
            ? ($totalPriceProductBundle / $history->total_price) * 100
            : 0;

        $totalPercentageProductBundle = round($totalPercentageProductBundle, 2);

        //sale
        $totalPriceSales = 0;

        $getProductSales = Sale::where('code_document', $history->code_document)
            ->selectRaw('COALESCE(actual_product_old_price_sale, product_old_price_sale) as actual_product_old_price_sale')
            ->cursor();

        foreach ($getProductSales as $product) {
            $totalPriceSales += $product->actual_product_old_price_sale;
        }

        // Menghitung persentase sales terhadap total price
        $totalPercentageSales = $history->total_price != 0
            ? ($totalPriceSales / $history->total_price) * 100
            : 0;

        $totalPercentageSales = round($totalPercentageSales, 2);

        //discrepancy

        if ($history->status_file === 0) {
            $getProductDiscrepancy = Product_old::where('code_document', $history->code_document)->cursor();
            $totalPriceDiscrepancy = 0;
            foreach ($getProductDiscrepancy as $product) {
                $totalPriceDiscrepancy += $product->old_price_product;
            }

            $totalPercentageDiscrepancy = $history->total_price != 0
                ? ($totalPriceDiscrepancy / $history->total_price) * 100
                : 0;
            $totalPercentageDiscrepancy = round($totalPercentageDiscrepancy, 2);
        } else if ($history->status_file === 1) {

            $totalPercentageDiscrepancy = $history->total_price != 0
                ? ($history->value_data_discrepancy / $history->total_price) * 100
                : 0;
            $totalPercentageDiscrepancy = round($totalPercentageDiscrepancy, 2);
        }

        $totalPercentageLolos = $history->total_price != 0
            ? ($totalOldPriceLolos + $totalPriceLolosStg + $totalPriceLolosAp + $totalPriceSales + $totalPriceProductBundle) / $history->total_price * 100
            : 0;
        $totalPercentageLolos = round($totalPercentageLolos, 2);

        $valueDataLolos = round($totalOldPriceLolos + $totalPriceLolosStg + $totalPriceLolosAp + $totalPriceSales + $totalPriceProductBundle, 2);
        if ($history->status_file === 1) {
            $valueDataDamaged = round($totalOldPriceDamaged, 2);
            $valueDataAbnormal = round($totalOldPriceAbnormal, 2);
        } else {
            $valueDataDamaged = round($totalOldPriceDamaged + $totalPriceDamagedStg + $totalPriceDamagedAp, 2);
            $valueDataAbnormal = round($totalOldPriceAbnormal + $totalPriceAbnormalStg + $totalPriceAbnormalAp, 2);
        }

        $totalPercentageAbnormal = $history->total_price != 0
            ? ($valueDataAbnormal / $history->total_price) * 100
            : 0;
        $totalPercentageAbnormal = round($totalPercentageAbnormal, 2);
        $totalPercentageDamaged = $history->total_price != 0
            ? ($valueDataDamaged / $history->total_price) * 100
            : 0;
        $totalPercentageDamaged = round($totalPercentageDamaged, 2);

        // Response
        $response = new ResponseResource(true, "Riwayat Check", [
            'id' => $history->id,
            'user_id' => $history->user_id,
            'code_document' => $history->code_document,
            'base_document' => $history->base_document,
            'total_product_category' => $productCategoryCount,
            'total_product_color' => $productColorCount,
            'total_product_sales' => count($getProductSales),
            'total_product_stagings' => $totalStagings,
            'total_product_approve' => $totalPA,
            'total_product_bundle' => count($getProductProductBundle),
            'total_data' => $history->total_data,
            'total_data_in' => $history->total_data_in,
            'total_price_in' => $history->total_price_in ?? null,
            'total_data_lolos' => $history->total_data_lolos,
            'total_data_damaged' => $history->total_data_damaged,
            'total_data_abnormal' => $history->total_data_abnormal,
            'total_discrepancy' => $history->total_discrepancy,
            'status_approve' => $history->status_approve,
            'precentage_total_data' => $history->precentage_total_data,
            'percentage_in' => $history->percentage_in,
            'percentage_lolos' => $history->percentage_lolos,
            'percentage_damaged' => $history->percentage_damaged,
            'percentage_abnormal' => $history->percentage_abnormal,
            'percentage_discrepancy' => $history->percentage_discrepancy,
            'total_price' => $history->total_price,
            'created_at' => $history->created_at,
            'updated_at' => $history->updated_at,
            'value_data_lolos' => $valueDataLolos,
            'value_data_damaged' => $valueDataDamaged,
            'value_data_abnormal' => $valueDataAbnormal,
            'damaged' => [
                'total_old_price' => $totalOldPriceDamaged,
                'price_percentage' => $totalPercentageDamaged,
            ],
            'lolos' => [
                'total_old_price' => $totalOldPriceLolos + $totalPriceLolosStg + $totalPriceLolosAp + $totalPriceSales + $totalPriceProductBundle,
                'price_percentage' => $totalPercentageLolos,
            ],
            'abnormal' => [
                'total_old_price' => $totalOldPriceAbnormal,
                'price_percentage' => $totalPercentageAbnormal,
            ],
            // 'damagedStaging' => [
            //     'total_old_price' => $totalPriceDamagedStg,
            //     'price_percentage' => $totalPercentageDamagedStg,
            // ],
            // 'lolosStaging' => [
            //     'total_old_price' => $totalPriceLolosStg,
            //     'price_percentage' => $totalPercentageLolosStg,
            // ],
            // 'abnormalStaging' => [
            //     'total_old_price' => $totalPriceAbnormalStg,
            //     'price_percentage' => $totalPercentageAbnormalStg,
            // ],
            // 'damagedAp' => [
            //     'total_old_price' => $totalPriceDamagedAp,
            //     'price_percentage' => $totalPercentageDamagedAp,
            // ],
            // 'lolosAp' => [
            //     'total_old_price' => $totalPriceLolosAp,
            //     'price_percentage' => $totalPercentageLolosAp,
            // ],
            // 'abnormalAp' => [
            //     'total_old_price' => $totalPriceAbnormalAp,
            //     'price_percentage' => $totalPercentageAbnormal,
            // ],
            'lolosSale' => [
                'total_old_price' => $totalPriceSales,
                'price_percentage' => $totalPercentageSales,
            ],
            'lolosBundle' => [
                'total_old_price' => $totalPriceProductBundle,
                'price_percentage' => $totalPercentageProductBundle,
            ],
            'priceDiscrepancy' =>  $history->value_data_discrepancy ?? null,
            'price_percentage' => $totalPercentageDiscrepancy,
        ]);

        return $response->response();
    }

    public function getByDocument(Request $request)
    {
        $codeDocument = RiwayatCheck::where('code_document', $request['code_document']);
        return new ResponseResource(true, "Riwayat Check", $codeDocument);
    }

    public function edit(RiwayatCheck $riwayatCheck)
    {
        //
    }

    public function update(Request $request, RiwayatCheck $riwayatCheck) {}

    public function destroy(RiwayatCheck $history)
    {
        DB::beginTransaction();
        try {
            Notification::where('riwayat_check_id', $history->id)->delete();
            $history->delete();
            DB::commit();
            return new ResponseResource(true, 'data berhasil di hapus', $history);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, 'data gagal di hapus', $e->getMessage());
        }
    }

    public function exportToExcel(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');
        $code_document = $request->input('code_document');

        // Mengambil history secara efisien
        $getHistory = RiwayatCheck::where('code_document', $code_document)->first();

        //product old
        $totalOldPriceDiscrepancy = 0;
        $getProductDiscrepancy = [];
        Product_old::where('code_document', $code_document)
            ->chunk(2000, function ($products) use (&$getProductDiscrepancy, &$totalOldPriceDiscrepancy) {
                foreach ($products as $product) {
                    $getProductDiscrepancy[] = $product;
                    $totalOldPriceDiscrepancy += $product->old_price_product;
                }
            });

        $price_persentage_dp = $getHistory->total_price != 0
            ? ($totalOldPriceDiscrepancy / $getHistory->total_price) * 100
            : 0;
        $price_persentage_dp = round($price_persentage_dp, 2);

        // new_product
        $getProductDamaged = [];
        $totalOldPriceDamaged = 0;
        New_product::where('code_document', $code_document)
            ->where('actual_new_quality->damaged', '!=', null)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductDamaged, &$totalOldPriceDamaged) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->damaged_value = json_decode($product->actual_new_quality)->damaged ?? null;
                    $getProductDamaged[] = $product;
                    $totalOldPriceDamaged += $product->actual_old_price_product;
                }
            });

        $price_persentage_damaged = $getHistory->total_price != 0
            ? ($totalOldPriceDamaged / $getHistory->total_price) * 100
            : 0;
        $price_persentage_damaged = round($price_persentage_damaged, 2);

        $getProductLolos = [];
        $totalOldPriceLolos = 0;
        New_product::where('code_document', $code_document)
            ->where('actual_new_quality->lolos', '!=', null)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductLolos, &$totalOldPriceLolos) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->lolos_value = json_decode($product->actual_new_quality)->lolos ?? null;
                    $getProductLolos[] = $product;
                    $totalOldPriceLolos += $product->actual_old_price_product;
                }
            });
        $price_persentage_lolos = $getHistory->total_price != 0
            ? ($totalOldPriceLolos / $getHistory->total_price) * 100
            : 0;
        $price_persentage_lolos = round($price_persentage_lolos, 2);

        $getProductAbnormal = [];
        $totalOldPriceAbnormal = 0;
        New_product::where('code_document', $code_document)
            ->where('actual_new_quality->abnormal', '!=', null)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductAbnormal, &$totalOldPriceAbnormal) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->abnormal_value = json_decode($product->actual_new_quality)->abnormal ?? null;
                    $getProductAbnormal[] = $product;
                    $totalOldPriceAbnormal += $product->actual_old_price_product;
                }
            });

        $price_persentage_abnormal = $getHistory->total_price != 0
            ? ($totalOldPriceAbnormal / $getHistory->total_price) * 100
            : 0;
        $price_persentage_abnormal = round($price_persentage_abnormal, 2);

        // staging
        $getProductStagings = [];
        $totalOldPriceStaging = 0;
        StagingProduct::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductStagings, &$totalOldPriceStaging) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->lolos_value = json_decode($product->actual_new_quality)->lolos ?? null;
                    $getProductStagings[] = $product;
                    $totalOldPriceStaging += $product->actual_old_price_product;
                }
            });

        $price_persentage_staging = $getHistory->total_price != 0
            ? ($totalOldPriceStaging / $getHistory->total_price) * 100
            : 0;
        $price_persentage_staging = round($price_persentage_staging, 2);

        //product approve
        $getProductPA = [];
        $totalOldPricePA = 0;
        ProductApprove::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductPA, &$totalOldPricePA) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->lolos_value = json_decode($product->actual_new_quality)->lolos ?? null;
                    $getProductPA[] = $product;
                    $totalOldPricePA += $product->actual_old_price_product;
                }
            });

        $price_persentage_product_approve = $getHistory->total_price != 0
            ? ($totalOldPricePA / $getHistory->total_price) * 100
            : 0;
        $price_persentage_product_approve = round($price_persentage_product_approve, 2);

        //product bundle
        $getProductBundle = [];
        $totalOldPriceBundle = 0;
        Product_Bundle::where('code_document', $code_document)
            ->whereNot('new_status_product', 'sale')
            ->chunk(2000, function ($products) use (&$getProductBundle, &$totalOldPriceBundle) {
                foreach ($products as $product) {
                    // Fallback ke kolom asli jika actual_ null
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;
                    $product->actual_new_quality = $product->actual_new_quality ?? $product->new_quality;
                    $product->lolos_value = json_decode($product->actual_new_quality)->lolos ?? null;
                    $getProductBundle[] = $product;
                    $totalOldPriceBundle += $product->actual_old_price_product;
                }
            });

        $price_persentage_bundle = $getHistory->total_price != 0
            ? ($totalOldPriceBundle / $getHistory->total_price) * 100
            : 0;
        $price_persentage_bundle = round($price_persentage_bundle, 2);

        //sales
        $totalPriceSales = 0;

        $getProductSales = Sale::where('code_document', $getHistory->code_document)->cursor();

        foreach ($getProductSales as $product) {
            // Fallback ke kolom asli jika actual_ null
            $product->actual_product_old_price_sale = $product->actual_product_old_price_sale ?? $product->product_old_price_sale;
            $totalPriceSales += $product->actual_product_old_price_sale;
        }

        // Menghitung persentase sales terhadap total price
        $totalPercentageSales = $getHistory->total_price != 0
            ? ($totalPriceSales / $getHistory->total_price) * 100
            : 0;

        $totalPercentageSales = round($totalPercentageSales, 2);

        // Validasi jika data kosong
        $checkHistory = RiwayatCheck::where('code_document', $code_document)->get();
        if ($checkHistory->isEmpty()) {
            return response()->json(['status' => false, 'message' => "Data kosong, tidak bisa di export"], 422);
        }

        // Proses pembuatan file Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set data ke lembar Excel
        $headers = [
            'Code Document',
            'Base Document',
            'Total Data',
            'Total Data In',
            'Total Data Lolos',
            'Total Data Damaged',
            'Total Data Abnormal',
            'Total Discrepancy',
            'Status Approve',
            'Percentage Total Data',
            'Percentage In',
            'Percentage Lolos',
            'Percentage Damaged',
            'Percentage Abnormal',
            'Percentage Discrepancy',
            'Total Price'
        ];

        $currentRow = 1;
        foreach ($checkHistory as $riwayatCheck) {
            foreach ($headers as $index => $header) {
                $columnName = strtolower(str_replace(' ', '_', $header));
                $cellValue = $riwayatCheck->$columnName;
                // Set header
                $sheet->setCellValueByColumnAndRow(1, $currentRow, $header);
                // Set value
                $sheet->setCellValueByColumnAndRow(2, $currentRow, $cellValue);
                $currentRow++;
            }
            $currentRow++;
        }

        // Buat file Excel untuk setiap kategori produk
        $this->createExcelSheet($spreadsheet, 'Damaged-Inventory', $getProductDamaged, $totalOldPriceDamaged, $price_persentage_damaged);
        $this->createExcelSheet($spreadsheet, 'Lolos-Inventory', $getProductLolos, $totalOldPriceLolos, $price_persentage_lolos);
        $this->createExcelSheetAbnormal($spreadsheet, 'Abnormal-Inventory', $getProductAbnormal, $totalOldPriceAbnormal, $price_persentage_abnormal);
        $this->createExcelSheet($spreadsheet, 'Staging', $getProductStagings, $totalOldPriceStaging, $price_persentage_staging);
        $this->createExcelSheet($spreadsheet, 'Product Approve', $getProductPA, $totalOldPricePA, $price_persentage_product_approve);
        $this->createExcelSheet($spreadsheet, 'Product-bundle', $getProductBundle, $totalOldPriceBundle, $price_persentage_bundle);
        $this->createExcelSale($spreadsheet, 'Sales', $getProductSales, $totalPriceSales, $totalPercentageSales);
        $this->createExcelSheetDiscrepancy($spreadsheet, 'Discrepancy', $getProductDiscrepancy, $totalOldPriceDiscrepancy, $price_persentage_dp);

        $firstItem = $checkHistory->first();

        $writer = new Xlsx($spreadsheet);
        $fileName = $firstItem->base_document;
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "File siap diunduh.", $downloadUrl);
    }

    private function createExcelSheet($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Old Barcode',
            'New Barcode',
            'Name Product',
            'Keterangan',
            'Qty',
            'Unit Price',
            'Category',
            'Diskon',
            'After Diskon',
            'Price Percentage'
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            // Gunakan actual_old_price_product dengan fallback
            $actualOldPrice = $item->actual_old_price_product ?? $item->old_price_product;

            $diskon = $actualOldPrice != 0
                ? (($actualOldPrice - $item->new_price_product) / $actualOldPrice) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? 'null';

            // Menambahkan data ke array
            $dataArray[] = [
                $item->code_document ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->new_barcode_product ?? 'null',
                $item->new_name_product ?? 'null',
                $keterangan,
                $item->new_quantity_product ?? 'null',
                $actualOldPrice ?? 'null',
                $item->new_category_product ?? 'null',
                $diskon ?? 'null',
                $item->new_price_product ?? 'null',
                $pricePercentage
            ];
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }
    private function createExcelSheetAbnormal($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Old Barcode',
            'New Barcode',
            'Name Product',
            'Keterangan',
            'Qty',
            'Unit Price',
            'Category',
            'Diskon',
            'After Diskon',
            'Price Percentage',
            'Keterangan'
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            // Gunakan actual_old_price_product dengan fallback
            $actualOldPrice = $item->actual_old_price_product ?? $item->old_price_product;

            $diskon = $actualOldPrice != 0
                ? (($actualOldPrice - $item->new_price_product) / $actualOldPrice) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? 'null';

            // Menambahkan data ke array
            $dataArray[] = [
                $item->code_document ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->new_barcode_product ?? 'null',
                $item->new_name_product ?? 'null',
                $keterangan,
                $item->new_quantity_product ?? 'null',
                $actualOldPrice ?? 'null',
                $item->new_category_product ?? 'null',
                $diskon ?? 'null',
                $item->new_price_product ?? 'null',
                $pricePercentage,
                'Abnormal'
            ];
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }

    private function createExcelSale($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Name Product',
            'New Barcode',
            'Qty',
            'Unit Price',
            'Category',
            'Price After Diskon',
            'Price Percentage',
            'Old Barcode',
            'Status Product',
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            // Gunakan actual_product_old_price_sale dengan fallback
            $actualOldPriceSale = $item->actual_product_old_price_sale ?? $item->product_old_price_sale;

            // Menambahkan data ke array sesuai urutan header
            $dataArray[] = [
                $item->code_document_sale ?? 'null',
                $item->product_name_sale ?? 'null',
                $item->product_barcode_sale ?? 'null',
                $item->product_qty_sale ?? 'null',
                $actualOldPriceSale ?? 'null',
                $item->product_category_sale ?? 'null',
                $item->product_price_sale ?? 'null',
                $pricePercentage,
                $item->old_barcode_product ?? 'null',
                $item->status_product ?? 'null',
            ];
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }

    private function createExcelSheetDiscrepancy($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Old Barcode',
            'Name Product',
            'Qty',
            'Unit Price',
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            $diskon = $item->old_price_product != 0
                ? (($item->old_price_product - $item->new_price_product) / $item->old_price_product) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? 'null';

            // Menambahkan data ke array
            $dataArray[] = [
                $item->code_document ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->old_name_product ?? 'null',
                $item->old_quantity_product ?? 'null',
                $item->old_price_product ?? 'null',

            ];
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
    }

    // kita akan membuat function yang mengechek old_barcode_product dan old_price_product sama dari patokan kita mencari dari tabel product_olds ke barcode_damageds
    public function updatePricesFromExcel(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds sebagai reference (Excel data)
            // Filter hanya data dengan old_price_product yang valid (numeric) dan code_document yang sesuai
            $excelData = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document')
                ->where('code_document', $codeDocument)
                ->whereNotNull('old_price_product')
                ->where('old_price_product', '!=', '')
                ->get()
                ->filter(function ($item) {
                    // Filter hanya yang old_price_product numeric
                    return is_numeric($item->old_price_product) && $item->old_price_product > 0;
                })
                ->keyBy('old_barcode_product');

            if ($excelData->isEmpty()) {
                return new ResponseResource(false, "Tidak ada data Excel yang valid untuk code_document '{$codeDocument}'. Pastikan kolom old_price_product berisi angka yang valid.", null);
            }

            // Define semua tabel yang akan diupdate
            $tablesToUpdate = [
                'new_products' => \App\Models\New_product::class,
                'staging_products' => \App\Models\StagingProduct::class,
                // 'staging_approves' => \App\Models\StagingApprove::class,
                // 'filter_stagings' => \App\Models\FilterStaging::class,
                'product_bundles' => \App\Models\Product_Bundle::class,
                'product_approves' => \App\Models\ProductApprove::class,
                // 'repair_filters' => \App\Models\RepairFilter::class,
                'repair_products' => \App\Models\RepairProduct::class,
                // 'product_olds' => \App\Models\Product_old::class,
                'sales' => \App\Models\Sale::class,
            ];

            $updateResults = [];
            $invalidData = [];
            $summary = [
                'total_excel_records' => $excelData->count(),
                'total_records_updated' => 0,
                'total_records_not_found' => 0,
                'total_invalid_prices' => 0,
                'tables_updated' => [],
            ];

            // Mulai transaction setelah validasi data awal
            DB::beginTransaction();

            // Loop through setiap barcode dari Excel
            foreach ($excelData as $barcode => $excelRecord) {
                $foundAndUpdated = false;
                $newPrice = $excelRecord->actual_old_price_product;

                // Validasi tambahan untuk price
                if (!is_numeric($newPrice) || $newPrice <= 0) {
                    $summary['total_invalid_prices']++;
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'invalid_price' => $newPrice,
                        'reason' => 'Price is not numeric or less than or equal to 0'
                    ];
                    continue;
                }

                // Convert ke float untuk memastikan format yang benar
                $newPrice = (float) $newPrice;

                // Update di semua tabel sistem
                foreach ($tablesToUpdate as $tableName => $modelClass) {
                    $priceColumn = ($tableName === 'sales') ? 'actual_product_old_price_sale' : 'actual_old_price_product';

                    try {
                        // Prepare data untuk update
                        $updateData = [$priceColumn => $newPrice];

                        // Tambahan update untuk new_quality atau status_product
                        if ($tableName === 'sales') {
                            // Untuk tabel sales, update status_product
                            $updateData['status_product'] = 'abnormal';
                        } else {
                            // Untuk tabel lainnya, update new_quality
                            $updateData['new_quality'] = json_encode([
                                'lolos' => null,
                                'damaged' => null,
                                'abnormal' => 'FRAUD & OVERPRICE'
                            ]);
                        }

                        // Cari dan update record yang sesuai berdasarkan barcode dan code_document
                        $updatedCount = $modelClass::where('old_barcode_product', $barcode)
                            ->where('code_document', $codeDocument)
                            ->update($updateData);

                        if ($updatedCount > 0) {
                            $foundAndUpdated = true;
                            $summary['total_records_updated'] += $updatedCount;

                            // Track tabel mana yang diupdate
                            if (!isset($summary['tables_updated'][$tableName])) {
                                $summary['tables_updated'][$tableName] = 0;
                            }
                            $summary['tables_updated'][$tableName] += $updatedCount;

                            $updateResults[] = [
                                'barcode' => $barcode,
                                'table' => $tableName,
                                'new_price' => $newPrice,
                                'updated_count' => $updatedCount,
                                'status' => 'updated',
                                'additional_updates' => $tableName === 'sales'
                                    ? ['status_product' => 'abnormal']
                                    : ['new_quality' => 'FRAUD & OVERPRICE']
                            ];
                        }
                    } catch (\Exception $tableError) {
                        // Log error untuk tabel tertentu tapi lanjutkan ke tabel lain
                        Log::error("Error updating table {$tableName} for barcode {$barcode}: " . $tableError->getMessage());
                        $invalidData[] = [
                            'barcode' => $barcode,
                            'table' => $tableName,
                            'price' => $newPrice,
                            'error' => $tableError->getMessage()
                        ];
                    }
                }

                // Jika tidak ditemukan di sistem
                if (!$foundAndUpdated) {
                    $summary['total_records_not_found']++;
                    $updateResults[] = [
                        'barcode' => $barcode,
                        'table' => null,
                        'new_price' => $newPrice,
                        'updated_count' => 0,
                        'status' => 'not_found'
                    ];
                }
            }

            DB::commit();

            return new ResponseResource(true, "Update prices completed successfully", [
                'summary' => $summary,
                'details' => $updateResults,
                'invalid_data' => $invalidData
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error during update: " . $e->getMessage(), null);
        }
    }

    //ini membuat new_quality nya menjadi abnormal
    public function updatePricesFromExcel2(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil barcode dari barcode_damageds untuk code_document yang diminta
            $barcodes = \App\Models\BarcodeDamaged::where('code_document', $codeDocument)
                ->pluck('old_barcode_product')
                ->unique()
                ->values();

            if ($barcodes->isEmpty()) {
                return new ResponseResource(false, "Tidak ada barcode untuk code_document '{$codeDocument}'.", null);
            }

            // Define semua tabel yang akan diupdate
            $tablesToUpdate = [
                'new_products' => \App\Models\New_product::class,
                'staging_products' => \App\Models\StagingProduct::class,
                'product_bundles' => \App\Models\Product_Bundle::class,
                'product_approves' => \App\Models\ProductApprove::class,
                'repair_products' => \App\Models\RepairProduct::class,
                'sales' => \App\Models\Sale::class,
            ];

            $summary = [
                'total_barcodes' => $barcodes->count(),
                'total_records_updated' => 0,
                'tables_updated' => [],
            ];

            // Mulai transaction
            DB::beginTransaction();

            // Update di semua tabel
            foreach ($tablesToUpdate as $tableName => $modelClass) {
                try {
                    if ($tableName === 'sales') {
                        // Untuk tabel sales, update status_product dengan filter code_document_sale
                        $updatedCount = $modelClass::whereIn('old_barcode_product', $barcodes)
                            ->where('code_document_sale', $codeDocument)
                            ->update(['status_product' => 'abnormal']);
                    } else {
                        // Untuk tabel lainnya, update actual_new_quality dengan filter code_document
                        $updatedCount = $modelClass::whereIn('old_barcode_product', $barcodes)
                            ->where('code_document', $codeDocument)
                            ->update([
                                'actual_new_quality' => json_encode([
                                    'lolos' => null,
                                    'damaged' => null,
                                    'abnormal' => 'FRAUD & OVERPRICE'
                                ])
                            ]);
                    }

                    if ($updatedCount > 0) {
                        $summary['total_records_updated'] += $updatedCount;
                        $summary['tables_updated'][$tableName] = $updatedCount;
                    }
                } catch (\Exception $tableError) {
                    Log::error("Error updating table {$tableName}: " . $tableError->getMessage());
                }
            }

            DB::commit();

            return new ResponseResource(true, "Update completed successfully", [
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error during update: " . $e->getMessage(), null);
        }
    }

    public function validateExcelData(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds dengan filter code_document jika ada
            $query = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document');

            if ($codeDocument) {
                $query->where('code_document', $codeDocument);
            }

            $allData = $query->get();

            $validData = [];
            $invalidData = [];

            foreach ($allData as $record) {
                $barcode = $record->old_barcode_product;
                $price = $record->old_price_product;

                // Cek validitas data
                if (empty($barcode)) {
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'price' => $price,
                        'code_document' => $record->code_document,
                        'issue' => 'Barcode is empty'
                    ];
                } elseif (empty($price) || !is_numeric($price) || $price <= 0) {
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'price' => $price,
                        'code_document' => $record->code_document,
                        'issue' => 'Price is invalid (not numeric, empty, or <= 0)'
                    ];
                } else {
                    $validData[] = [
                        'barcode' => $barcode,
                        'price' => (float) $price,
                        'code_document' => $record->code_document
                    ];
                }
            }

            $summary = [
                'code_document_filter' => $codeDocument ?? 'All documents',
                'total_records' => $allData->count(),
                'valid_records' => count($validData),
                'invalid_records' => count($invalidData),
                'validation_percentage' => $allData->count() > 0 ? (count($validData) / $allData->count()) * 100 : 0
            ];

            return new ResponseResource(true, "Data validation completed", [
                'summary' => $summary,
                'invalid_data' => $invalidData,
                'sample_valid_data' => array_slice($validData, 0, 10) // Sample 10 data valid
            ]);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error during validation: " . $e->getMessage(), null);
        }
    }

    public function compareExcelWithSystem(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds sebagai reference (Excel data)
            $query = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document');

            if ($codeDocument) {
                $query->where('code_document', $codeDocument);
            }

            $excelData = $query->get()->keyBy('old_barcode_product');

            if ($excelData->isEmpty()) {
                $message = $codeDocument
                    ? "Tidak ada data Excel untuk code_document '{$codeDocument}' untuk dibandingkan."
                    : "Tidak ada data Excel untuk dibandingkan.";
                return new ResponseResource(false, $message . " Silakan upload file Excel terlebih dahulu.", null);
            }

            // Define semua tabel yang akan dicek
            $tablesToCheck = [
                // 'new_products' => \App\Models\New_product::class,
                // 'staging_products' => \App\Models\StagingProduct::class,
                // 'staging_approves' => \App\Models\StagingApprove::class,
                // 'filter_stagings' => \App\Models\FilterStaging::class,
                // 'product_bundles' => \App\Models\Product_Bundle::class,
                // 'product_approves' => \App\Models\ProductApprove::class,
                // 'repair_filters' => \App\Models\RepairFilter::class,
                // 'repair_products' => \App\Models\RepairProduct::class,
                // 'sales' => \App\Models\Sale::class,
                'product_olds' => \App\Models\Product_old::class,
            ];

            $discrepancies = [];
            $summary = [
                'code_document_filter' => $codeDocument ?? 'All documents',
                'total_excel_records' => $excelData->count(),
                'total_system_records_found' => 0,
                'total_price_mismatches' => 0,
                'total_missing_in_system' => 0,
                'excel_total_price' => $excelData->sum('old_price_product'),
                'system_total_price' => 0,
            ];

            // Loop through setiap barcode dari Excel
            foreach ($excelData as $barcode => $excelRecord) {
                $foundInSystem = false;
                $systemPrice = 0;
                $foundInTable = null;

                // Cari di semua tabel sistem
                foreach ($tablesToCheck as $tableName => $modelClass) {
                    $priceColumn = ($tableName === 'sales') ? 'product_old_price_sale' : 'old_price_product';

                    // Query dengan filter code_document jika ada
                    $query = $modelClass::where('old_barcode_product', $barcode);

                    if ($codeDocument) {
                        $query->where('code_document', $codeDocument);
                    }

                    $systemRecord = $query->select('old_barcode_product', $priceColumn)->first();

                    if ($systemRecord) {
                        $foundInSystem = true;
                        $systemPrice = $systemRecord->{$priceColumn};
                        $foundInTable = $tableName;
                        $summary['total_system_records_found']++;
                        $summary['system_total_price'] += $systemPrice;
                        break; // Stop searching setelah ditemukan
                    }
                }

                // Cek apakah ada discrepancy
                if (!$foundInSystem) {
                    // Barcode tidak ditemukan di sistem
                    $discrepancies[] = [
                        'barcode' => $barcode,
                        'excel_price' => $excelRecord->old_price_product,
                        'system_price' => null,
                        'found_in_table' => null,
                        'price_difference' => $excelRecord->old_price_product,
                        'status' => 'missing_in_system',
                        'issue' => 'Barcode tidak ditemukan di sistem'
                    ];
                    $summary['total_missing_in_system']++;
                } else if ($excelRecord->old_price_product != $systemPrice) {
                    // Barcode ditemukan tapi harga berbeda
                    $discrepancies[] = [
                        'barcode' => $barcode,
                        'excel_price' => $excelRecord->old_price_product,
                        'system_price' => $systemPrice,
                        'found_in_table' => $foundInTable,
                        'price_difference' => $excelRecord->old_price_product - $systemPrice,
                        'status' => 'price_mismatch',
                        'issue' => 'Harga tidak sesuai antara Excel dan sistem'
                    ];
                    $summary['total_price_mismatches']++;
                }
            }

            // Hitung total price difference
            $summary['total_price_difference'] = $summary['excel_total_price'] - $summary['system_total_price'];
            $summary['total_discrepancies'] = count($discrepancies);

            // Hitung total selisih dari semua discrepancies
            $totalSelisih = 0;
            foreach ($discrepancies as $discrepancy) {
                $totalSelisih += abs($discrepancy['price_difference']);
            }

            // Extract hanya barcode dari discrepancies
            $onlyBarcodes = array_column($discrepancies, 'barcode');

            return new ResponseResource(true, "Comparison completed", [
                'summary' => $summary,
                'total_selisih_harga' => $totalSelisih,
                'barcodes' => $onlyBarcodes,
                'jumlah_barcode_bermasalah' => count($onlyBarcodes)
            ]);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error during comparison: " . $e->getMessage(), null);
        }
    }
}
