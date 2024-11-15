<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\Product_old;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\DeleteDuplicateProductsJob;

use Illuminate\Support\Facades\Redis;


class StagingApproveController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');

        $newProducts = StagingApprove::latest()->where(function ($queryBuilder) use ($query) {
            $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                ->orWhere('new_name_product', 'LIKE', '%' . $query . '%');
        })->whereNotIn('new_status_product', ['dump', 'expired', 'sale', 'migrate', 'repair'])->paginate(100);

        return new ResponseResource(true, "list new product", $newProducts);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {}

    /**
     * Display the specified resource.
     */
    public function show(StagingApprove $stagingApprove)
    {
        return new ResponseResource(true, "data new product", $stagingApprove);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(StagingApprove $stagingApprove)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StagingApprove $stagingApprove)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $product_filter = StagingApprove::findOrFail($id);
            StagingProduct::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product bundle", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function stagingTransaction()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $user = User::with('role')->find(auth()->id());
        DB::beginTransaction();
        try {
            if ($user) {
                if ($user->role && ($user->role->role_name == 'Kasir leader' || $user->role->role_name == 'Admin' || $user->role->role_name == 'Spv')) {

                    $productApproves = StagingApprove::get();

                    foreach ($productApproves as $productApprove) {
                        $duplicate = New_product::where('new_barcode_product', $productApprove->new_barcode_product)->exists();
                        if ($duplicate) {
                            return new ResponseResource(false, "barcoede product di inventory sudah ada : " . $productApprove->new_barcode_product, null);
                        }
                    }

                    $chunkedProductApproves = $productApproves->chunk(100);

                    foreach ($chunkedProductApproves as $chunk) {
                        $dataToInsert = [];

                        foreach ($chunk as $productApprove) {
                            $dataToInsert[] = [
                                'code_document' => $productApprove->code_document,
                                'old_barcode_product' => $productApprove->old_barcode_product,
                                'new_barcode_product' => $productApprove->new_barcode_product,
                                'new_name_product' => $productApprove->new_name_product,
                                'new_quantity_product' => $productApprove->new_quantity_product,
                                'new_price_product' => $productApprove->new_price_product,
                                'old_price_product' => $productApprove->old_price_product,
                                'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                                'new_status_product' => $productApprove->new_status_product,
                                'new_quality' => $productApprove->new_quality,
                                'new_category_product' => $productApprove->new_category_product,
                                'new_tag_product' => $productApprove->new_tag_product,
                                'new_discount' => $productApprove->new_discount,
                                'display_price' => $productApprove->display_price,
                                'type' => $productApprove->type,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            // Hapus data dari StagingApprove
                            $productApprove->delete();
                        }

                        // Masukkan data ke New_product
                        New_product::insert($dataToInsert);
                    }

                    DB::commit();
                    return new ResponseResource(true, 'Transaksi berhasil diapprove', null);
                } else {
                    return new ResponseResource(false, "notification tidak ditemukan", null);
                }
            } else {
                return (new ResponseResource(false, "User tidak dikenali", null))->response()->setStatusCode(404);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal", $e->getMessage());
        }
    }

    public function countBast(Request $request)
    {
        // Memperpanjang waktu eksekusi dan batas memori
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        $inventory = New_product::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $stagings = StagingProduct::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $stagingApproves = StagingApprove::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $filterStagings = FilterStaging::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $productBundle = Product_Bundle::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $sales = Sale::where('code_document', '0130/10/2024')->select('code_document')->get();

        $productApprove = ProductApprove::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $repairFilter = RepairFilter::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $repairProduct = RepairProduct::where('code_document', '0130/10/2024')
            ->select('old_barcode_product')->get();

        $allData = count($inventory) + count($stagings) + count($filterStagings) + count($productBundle)
         + count($productApprove) + count($repairFilter) + count($repairProduct) + count($sales) + count($stagingApproves);

        // Cek duplikasi di dalam $combined dan $product_all
        $duplicates_combined = $combined->duplicates();
        $duplicates_product_all = $product_all->duplicates();

        // Menampilkan hasil debugging
        return [
            'total_product_all' => count($product_all),
            // 'total_combined' => count($combined),
            'totaldiff' => count($product_all->diff($combined)),
            // 'duplicates_combined' => count($duplicates_combined),
            // 'duplicates_product_all' => count($duplicates_product_all),
            'unique_barcodes' => $product_all->diff($combined),
        ];
    }

    public function dataSelection()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Ambil semua barcode dari `code_document` = '0001/11/2024' sebagai acuan
        $productOldInit = Product_old::where('code_document', '0001/11/2024')
            ->pluck('old_barcode_product')
            ->toArray();

        // Jalankan proses penghapusan pada data dengan `code_document` = '0003/11/2024'
        $data = Product_old::where('code_document', '0004/11/2024')->cursor()->each(function ($product) use (&$productOldInit) {
            // Jika barcode di data '0003/11/2024' ada di daftar '0001/11/2024', hapus dari kedua code_document
            if (in_array($product->old_barcode_product, $productOldInit)) {
                $product->delete(); // Hapus dari '0003/11/2024'

                // Cari indeks barcode yang cocok di '0001/11/2024' dan hapus
                $index = array_search($product->old_barcode_product, $productOldInit);
                if ($index !== false) {
                    // Hapus data yang ditemukan di `code_document` '0001/11/2024'
                    Product_old::where('code_document', '0001/11/2024')
                        ->where('old_barcode_product', $product->old_barcode_product)
                        ->delete();

                    // Hapus barcode dari array untuk menghindari pengecekan ulang
                    unset($productOldInit[$index]);
                }
            }
        });

        return new ResponseResource(true, "Berhasil melakukan seleksi dan penghapusan data duplikat", $data);
    }

    public function findSimilarTabel(Request $request)
    {
        // $lolos = New_product::where('code_document', '0068/09/2024')
        //     ->pluck('new_barcode_product');

        // $sales = Sale::latest()->pluck('product_barcode_sale');

        $product_olds = Product_old::where('code_document', '0003/11/2024')->pluck('old_barcode_product');

        // Menggabungkan data $lolos dan $sales
        // $combined = $lolos->merge($sales);

        // Memeriksa barcode yang duplikat
        $duplicateBarcodes = $product_olds->duplicates();

        // $stagings = StagingProduct::where('code_document', '0068/09/2024')
        //     ->pluck('new_name_product');

        // $approve = StagingApprove::where('code_document', '0068/09/2024')
        //     ->pluck('old_barcode_product');

        // $product_olds2 = Product_old::where('code_document', '0068/09/2024')
        //     ->pluck('old_name_product');

        // $approve = Product_Bundle::where('code_document', '0068/09/2024')
        //     ->pluck('new_name_product');

        // $combined = $lolos->merge($stagings)->merge($product_olds2)->merge($sales)->merge($approve);

        // Menggabungkan dua koleksi ($lolos dan $sales)

        // Mengembalikan data duplikat jika ada, atau pesan jika tidak ada
        if ($duplicateBarcodes->isNotEmpty()) {
           
            return response()->json($duplicateBarcodes);
        } else {
            return response()->json("Tidak ada data duplikat.");
        }
    }

    public function cacheProductBarcodes()
    {
        // Cache barcodes dari '0001/11/2024' di Redis
        $productOldInit = Product_old::where('code_document', '0001/11/2024')
            ->pluck('old_barcode_product')
            ->toArray();

        // Simpan data di Redis dengan TTL, misalnya 10 menit
        Redis::set('product_old:code_document:0001', json_encode($productOldInit), 'EX', 600);

        return new ResponseResource(true, "Data berhasil di-cache di Redis", []);
    }

    public function dataSelectionRedis()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
    
        // Menyimpan barcode di Redis sebelum menjalankan job
        $barcodes = Product_old::pluck('old_barcode_product')->toArray();
        Redis::set('product_old:code_document:0001', json_encode($barcodes), 'EX', 600);
    
        // Jalankan job untuk menghapus duplikat
        DeleteDuplicateProductsJob::dispatch(Product_old::class);
    
        return new ResponseResource(true, "Proses penghapusan duplikat sedang berjalan di background", []);
    }
    

}
