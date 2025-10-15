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
            ->select("new_category_product", "new_tag_product", "old_price_product")->cursor();

        $productCategoryCount = $getProduct->filter(function ($product) {
            return $product->new_category_product !== null;
        })->count();

        $productColorCount = $getProduct->filter(function ($product) {
            return $product->new_tag_product !== null;
        })->count();

        //new product ganti
        $totalOldPriceDamaged = 0;
        if ($history->status_file === 1) {
            $getProductDamaged = ProductDefect::where('riwayat_check_id', $history->id)->where('type', 'damaged')->get();
            $totalOldPriceDamaged = $getProductDamaged->sum('old_price_product');
            $totalPercentageDamaged = $history->total_price != 0
                ? ($totalOldPriceDamaged / $history->total_price) * 100
                : 0;
            $totalPercentageDamaged = round($totalPercentageDamaged, 2);
        } else {
            $getProductDamaged = New_product::where('code_document', $history->code_document)
                ->where('new_quality->damaged', '!=', null)
                ->select('old_price_product', 'new_quality')
                ->cursor();
            foreach ($getProductDamaged as $product) {
                $totalOldPriceDamaged += $product->old_price_product;
            }

            $totalPercentageDamaged = $history->total_price != 0
                ? ($totalOldPriceDamaged / $history->total_price) * 100
                : 0;

            $totalPercentageDamaged = round($totalPercentageDamaged, 2);
        }


        $totalOldPriceLolos = 0;
        $getProductLolos = New_product::where('code_document', $history->code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();

        foreach ($getProductLolos as $product) {
            $lolosValue = json_decode($product->new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalOldPriceLolos += $product->old_price_product;
            }
        }

        //ganti
        $totalOldPriceAbnormal = 0;
        if ($history->status_file === 1) {
            $getProductAbnormal = ProductDefect::where('riwayat_check_id', $history->id)->where('type', 'abnormal')->get();
            $totalOldPriceAbnormal = $getProductAbnormal->sum('old_price_product');
            $totalPercentageAbnormal = $history->total_price != 0
                ? ($totalOldPriceAbnormal / $history->total_price) * 100
                : 0;
            $totalPercentageAbnormal = round($totalPercentageAbnormal, 2);
        } else {
            $getProductAbnormal = New_product::where('code_document', $history->code_document)
                ->where('new_quality->abnormal', '!=', null)
                ->select('old_price_product', 'new_quality')
                ->cursor();

            foreach ($getProductAbnormal as $product) {
                $abnormalValue = json_decode($product->new_quality)->abnormal ?? null;
                if ($abnormalValue !== null) {
                    $totalOldPriceAbnormal += $product->old_price_product;
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
            ->where('new_quality->damaged', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();
        foreach ($getProductDamagedStg as $product) {
            $totalPriceDamagedStg += $product->old_price_product;
        }

        $totalPercentageDamagedStg = $history->total_price != 0
            ? ($totalPriceDamagedStg / $history->total_price) * 100
            : 0;

        $totalPercentageDamagedStg = round($totalPercentageDamagedStg, 2);

        $totalPriceLolosStg = 0;
        $getProductLolosStg = StagingProduct::where('code_document', $history->code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();

        foreach ($getProductLolosStg as $product) {
            $lolosValue = json_decode($product->new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalPriceLolosStg += $product->old_price_product;
            }
        }

        $totalPercentageLolosStg = $history->total_price != 0
            ? ($totalPriceLolosStg / $history->total_price) * 100
            : 0;
        $totalPercentageLolosStg = round($totalPercentageLolosStg, 2);

        $totalPriceAbnormalStg = 0;
        $getProductAbnormalStg = StagingProduct::where('code_document', $history->code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();

        foreach ($getProductAbnormalStg as $product) {
            $abnormalValue = json_decode($product->new_quality)->abnormal ?? null;
            if ($abnormalValue !== null) {
                $totalPriceAbnormalStg += $product->old_price_product;
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
            ->where('new_quality->damaged', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();
        foreach ($getProductDamagedAp as $product) {
            $totalPriceDamagedAp += $product->old_price_product;
        }

        $totalPercentageDamagedAp = $history->total_price != 0
            ? ($totalPriceDamagedAp / $history->total_price) * 100
            : 0;

        $totalPercentageDamagedAp = round($totalPercentageDamagedAp, 2);

        $totalPriceLolosAp = 0;
        $getProductLolosAp = ProductApprove::where('code_document', $history->code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();

        foreach ($getProductLolosAp as $product) {
            $lolosValue = json_decode($product->new_quality)->lolos ?? null;
            if ($lolosValue !== null) {
                $totalPriceLolosAp += $product->old_price_product;
            }
        }

        $totalPercentageLolosAp = $history->total_price != 0
            ? ($totalPriceLolosAp / $history->total_price) * 100
            : 0;
        $totalPercentageLolosAp = round($totalPercentageLolosAp, 2);

        $totalPriceAbnormalAp = 0;
        $getProductAbnormalAp = ProductApprove::where('code_document', $history->code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select('old_price_product', 'new_quality')
            ->cursor();

        foreach ($getProductAbnormalAp as $product) {
            $abnormalValue = json_decode($product->new_quality)->abnormal ?? null;
            if ($abnormalValue !== null) {
                $totalPriceAbnormalAp += $product->old_price_product;
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
            ->select('old_price_product', 'new_quality')
            ->cursor();
        foreach ($getProductProductBundle as $product) {
            $totalPriceProductBundle += $product->old_price_product;
        }

        $totalPercentageProductBundle = $history->total_price != 0
            ? ($totalPriceProductBundle / $history->total_price) * 100
            : 0;

        $totalPercentageProductBundle = round($totalPercentageProductBundle, 2);

        //sale
        $totalPriceSales = 0;

        $getProductSales = Sale::where('code_document', $history->code_document)
            ->select('product_old_price_sale')
            ->cursor();

        foreach ($getProductSales as $product) {
            $totalPriceSales += $product->product_old_price_sale;
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
        set_time_limit(600);
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
            ->where('new_quality->damaged', '!=', null)
            ->chunk(2000, function ($products) use (&$getProductDamaged, &$totalOldPriceDamaged) {
                foreach ($products as $product) {
                    $product->damaged_value = json_decode($product->new_quality)->damaged ?? null;
                    $getProductDamaged[] = $product;
                    $totalOldPriceDamaged += $product->old_price_product;
                }
            });

        $price_persentage_damaged = $getHistory->total_price != 0
            ? ($totalOldPriceDamaged / $getHistory->total_price) * 100
            : 0;
        $price_persentage_damaged = round($price_persentage_damaged, 2);

        $getProductLolos = [];
        $totalOldPriceLolos = 0;
        New_product::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->chunk(2000, function ($products) use (&$getProductLolos, &$totalOldPriceLolos) {
                foreach ($products as $product) {
                    $product->lolos_value = json_decode($product->new_quality)->lolos ?? null;
                    $getProductLolos[] = $product;
                    $totalOldPriceLolos += $product->old_price_product;
                }
            });
        $price_persentage_lolos = $getHistory->total_price != 0
            ? ($totalOldPriceLolos / $getHistory->total_price) * 100
            : 0;
        $price_persentage_lolos = round($price_persentage_lolos, 2);

        $getProductAbnormal = [];
        $totalOldPriceAbnormal = 0;
        New_product::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->chunk(2000, function ($products) use (&$getProductAbnormal, &$totalOldPriceAbnormal) {
                foreach ($products as $product) {
                    $product->abnormal_value = json_decode($product->new_quality)->abnormal ?? null;
                    $getProductAbnormal[] = $product;
                    $totalOldPriceAbnormal += $product->old_price_product;
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
            ->chunk(2000, function ($products) use (&$getProductStagings, &$totalOldPriceStaging) {
                foreach ($products as $product) {
                    $product->lolos_value = json_decode($product->new_quality)->lolos ?? null;
                    $getProductStagings[] = $product;
                    $totalOldPriceStaging += $product->old_price_product;
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
            ->chunk(2000, function ($products) use (&$getProductPA, &$totalOldPricePA) {
                foreach ($products as $product) {
                    $product->lolos_value = json_decode($product->new_quality)->lolos ?? null;
                    $getProductPA[] = $product;
                    $totalOldPricePA += $product->old_price_product;
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
            ->chunk(2000, function ($products) use (&$getProductBundle, &$totalOldPriceBundle) {
                foreach ($products as $product) {
                    $product->lolos_value = json_decode($product->new_quality)->lolos ?? null;
                    $getProductBundle[] = $product;
                    $totalOldPriceBundle += $product->old_price_product;
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
            $totalPriceSales += $product->product_old_price_sale;
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
        $this->createExcelSheet($spreadsheet, 'Abnormal-Inventory', $getProductAbnormal, $totalOldPriceAbnormal, $price_persentage_abnormal);
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
            $diskon = $item->old_price_product != 0
                ? (($item->old_price_product - $item->new_price_product) / $item->old_price_product) * 100
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
                $item->old_price_product ?? 'null',
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
            'After Diskon',
            'Price Percentage'
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {

            // Menambahkan data ke array
            $dataArray[] = [
                $item->code_document_sale ?? 'null',
                $item->product_name_sale ?? 'null',
                $item->product_barcode_sale ?? 'null',
                $item->product_quantity_sale ?? 'null',
                $item->product_old_price_sale ?? 'null',
                $item->product_category_sale ?? 'null',
                $item->total_discount_sale ?? 'null',
                $item->product_price_sale ?? 'null',
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
}
