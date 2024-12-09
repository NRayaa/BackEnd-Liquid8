<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BarcodeDamaged;
use App\Models\FilterStaging;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\Product_old;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StagingApproveController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $searchQuery = $request->input('q');
        $page = $request->input('page', 1);
        try {
            // Buat query dasar untuk StagingProduct
            $newProductsQuery = StagingProduct::query()
                ->select(
                    'id',
                    'new_barcode_product',
                    'new_name_product',
                    'new_category_product',
                    'new_price_product',
                    'new_status_product',
                    'display_price',
                    'new_date_in_product',
                    'stage'
                )
                ->where('stage', 'approve')
                ->latest();

            if ($searchQuery) {
                $newProductsQuery->where(function ($queryBuilder) use ($searchQuery) {
                    $queryBuilder->where('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
                });

                $page = 1;
            }

            // Terapkan pagination setelah pencarian selesai
            $paginatedProducts = $newProductsQuery->paginate(33, ['*'], 'page', $page);
            return new ResponseResource(true, "List of new products", $paginatedProducts);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(500);
        }
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
    public function store(Request $request) {}

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = StagingProduct::where('id', $id)->first();
        if($product){
            return new ResponseResource(true, "data product", $product);
        }else{
            return (new ResponseResource(true, "data product tidak ada", $product))->setStatusCode(404);

        }
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
            $product_filter = StagingProduct::findOrFail($id);
            $product_filter->update([
                'stage' => 'process'
            ]);
            // StagingProduct::create($product_filter->toArray());
            // $product_filter->delete();
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

                    $productApproves = StagingProduct::query()
                        ->where('stage', 'approve')
                        ->get();

                    $barcodesInInventory = New_product::whereIn('new_barcode_product', $productApproves->pluck('new_barcode_product'))->pluck('new_barcode_product');
                    $duplicates = $productApproves->filter(function ($productApprove) use ($barcodesInInventory) {
                        return $barcodesInInventory->contains($productApprove->new_barcode_product);
                    });

                    if ($duplicates->isNotEmpty()) {
                        return new ResponseResource(false, "Barcode product di inventory sudah ada: " . $duplicates->pluck('new_barcode_product')->implode(', '), null);
                    }

                    // Batasi pengolahan data dalam chunk 100 produk
                    $chunkedProductApproves = $productApproves->chunk(100);

                    foreach ($chunkedProductApproves as $chunk) {
                        // Siapkan data untuk insert ke New_product
                        $dataToInsert = $chunk->map(function ($productApprove) {
                            return [
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
                        })->toArray();

                        // Insert data ke tabel New_product
                        New_product::insert($dataToInsert);

                        StagingProduct::whereIn('id', $chunk->pluck('id'))->delete();
                    }

                    DB::commit();

                    // Kembalikan response sukses
                    return new ResponseResource(true, 'Transaksi berhasil diapprove', null);
                } else {
                    return new ResponseResource(false, "Role tidak memiliki izin", null);
                }
            } else {
                return (new ResponseResource(false, "User tidak dikenali", null))->response()->setStatusCode(404);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal memproses transaksi", $e->getMessage());
        }
    }


    public function findSimilarTabel(Request $request)
    {
        // Validasi input dokumen dari request
        $documents = $request->input('documents'); // Mengambil array dokumen dari request

        if (is_null($documents) || !is_array($documents)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter documents harus berupa array.',
            ], 400);
        }

        // Memperpanjang waktu eksekusi dan batas memori
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            // Mengambil semua data barcode berdasarkan kode dokumen
            $barcodes = Product_old::whereIn('code_document', $documents)
                ->pluck('old_barcode_product');

            // Jika tidak ada barcode ditemukan
            if ($barcodes->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tidak ada barcode yang ditemukan untuk dokumen yang diberikan.',
                    'data' => [],
                ]);
            }

            // Menghitung jumlah kemunculan setiap barcode
            $barcodeCounts = array_count_values($barcodes->toArray());

            // Filter barcode yang duplikat (lebih dari 1 kemunculan)
            $duplicateBarcodes = array_filter($barcodeCounts, function ($count) {
                return $count > 1;
            });

            // Jika ada barcode duplikat, simpan ke dalam tabel BarcodeDamaged
            if (!empty($duplicateBarcodes)) {
                foreach ($duplicateBarcodes as $barcode => $count) {
                    // Cari dokumen mana saja yang memiliki barcode tersebut
                    foreach ($documents as $document) {
                        $existsInDocument = Product_old::where('code_document', $document)
                            ->where('old_barcode_product', $barcode)
                            ->exists();

                        if ($existsInDocument) {
                            BarcodeDamaged::updateOrCreate(
                                [
                                    'code_document' => $document, // Simpan dokumen terkait barcode
                                    'old_barcode_product' => $barcode,
                                ],
                                [
                                    'occurrences' => $count, // Menyimpan jumlah duplikasi
                                ]
                            );
                        }
                    }
                }

                // Mengembalikan respon dengan data barcode duplikat
                return response()->json([
                    'status' => 'success',
                    'message' => 'Barcode duplikat berhasil diproses.',
                    'data' => $duplicateBarcodes,
                ]);
            }

            // Jika tidak ada barcode duplikat
            return response()->json([
                'status' => 'success',
                'message' => 'Tidak ada barcode duplikat yang ditemukan.',
                'data' => [],
            ]);
        } catch (\Exception $e) {

            // Mengembalikan respon error
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat memproses data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteDuplicateOldBarcodes(Request $request)
    {
        try {
            // Ambil semua barcode dari BarcodeDamaged berdasarkan kode dokumen tertentu
            $barcodes = BarcodeDamaged::where('code_document', $request->input('code_document'))
                ->pluck('old_barcode_product');

            // Daftar tabel yang akan dicek
            $tables = [
                New_product::class,
                StagingApprove::class,
                FilterStaging::class,
                StagingProduct::class,
                ProductApprove::class,
                // Product_old::class,
            ];

            $deletedCount = 0; // Variabel untuk menghitung jumlah data yang dihapus

            foreach ($barcodes as $barcode) {
                $found = false; // Flag untuk menandai apakah barcode sudah ditemukan di tabel sebelumnya

                foreach ($tables as $table) {
                    // Ambil semua ID untuk barcode yang sama di tabel ini
                    $records = $table::where('old_barcode_product', $barcode)->pluck('id')->sort();

                    if ($records->isEmpty()) {
                        continue; // Jika tidak ada data, lanjut ke tabel berikutnya
                    }

                    if (!$found) {
                        // Jika barcode belum ditemukan di tabel sebelumnya
                        // Simpan ID terkecil di tabel ini
                        $recordToKeep = $records->shift(); // Ambil dan hapus elemen pertama (ID terkecil)

                        // Hapus ID lainnya di tabel yang sama
                        $deletedCount += $table::whereIn('id', $records->toArray())->delete();

                        // Tandai bahwa barcode sudah ditemukan
                        $found = true;
                    } else {
                        // Jika barcode sudah ditemukan di tabel sebelumnya, hapus semua record di tabel ini
                        $deletedCount += $table::where('old_barcode_product', $barcode)->delete();
                    }
                }

                // Jika barcode ditemukan di tabel lain, hapus dari BarcodeDamaged
                if ($found) {
                    $deletedCount += BarcodeDamaged::where('old_barcode_product', $barcode)->delete();
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil menghapus data duplikat untuk setiap barcode, menyisakan satu data.',
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            // Tangkap error dan kembalikan respon error
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menghapus data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // public function countDifferent(Request $request)
    // {
    //     // Ambil barcode berdasarkan dokumen
    //     $docs1 = Product_old::where('code_document', '0211/11/2024')
    //         ->pluck('old_barcode_product');

    //     $docs2 = ProductApprove::where('code_document', '0211/11/2024')
    //         ->pluck('old_barcode_product');

    //     $docs2 = StagingProduct::where('code_document', '0211/11/2024')
    //         ->pluck('old_barcode_product');



    //     // Cari duplikasi menggunakan Laravel duplicates()
    //     $duplicates = $barcodes->duplicates();

    //     // Cek apakah ada duplikasi
    //     if ($duplicates->isNotEmpty()) {
    //         return new ResponseResource(true, "List barcode duplikat", $duplicates);
    //     } else {
    //         return new ResponseResource(true, "Tidak ada barcode duplikat", []);
    //     }
    // }

    public function findSimilarTabel2(Request $request)
    {
        // Validasi input dokumen dari request
        $documents = $request->input('documents'); // Mengambil array dokumen dari request

        if (is_null($documents) || !is_array($documents)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter documents harus berupa array.',
            ], 400);
        }

        // Memperpanjang waktu eksekusi dan batas memori
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            // Mengambil semua data barcode berdasarkan kode dokumen dari semua tabel
            $barcodesOld = Product_old::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesApprove = ProductApprove::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesStaging = StagingProduct::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesNew = New_product::whereIn('code_document', $documents)->pluck('old_barcode_product');

            // Gabungkan semua barcode
            $mergedBarcodes = $barcodesOld->merge($barcodesApprove)->merge($barcodesStaging)->merge($barcodesNew);


            // Jika tidak ada barcode ditemukan
            if ($mergedBarcodes->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Tidak ada barcode yang ditemukan untuk dokumen yang diberikan.',
                    'data' => [],
                ]);
            }

            // Menghitung jumlah kemunculan setiap barcode
            $barcodeCounts = array_count_values($mergedBarcodes->toArray());

            // Filter barcode yang duplikat (lebih dari 1 kemunculan)
            $duplicateBarcodes = array_filter($barcodeCounts, function ($count) {
                return $count > 1;
            });

            // Jika ada barcode duplikat
            if (!empty($duplicateBarcodes)) {
                foreach ($duplicateBarcodes as $barcode => $count) {
                    // Cari dokumen yang memiliki barcode tersebut di semua tabel
                    $relatedDocumentsOld = Product_old::where('old_barcode_product', $barcode)
                        ->whereIn('code_document', $documents)
                        ->pluck('code_document');

                    $relatedDocumentsApprove = ProductApprove::where('old_barcode_product', $barcode)
                        ->whereIn('code_document', $documents)
                        ->pluck('code_document');

                    $relatedDocumentsStaging = StagingProduct::where('old_barcode_product', $barcode)
                        ->whereIn('code_document', $documents)
                        ->pluck('code_document');

                    $relatedDocumentsNew = New_product::where('old_barcode_product', $barcode)
                        ->whereIn('code_document', $documents)
                        ->pluck('code_document');

                    // Gabungkan semua dokumen yang terkait dengan barcode
                    $relatedDocuments = $relatedDocumentsOld
                        ->merge($relatedDocumentsApprove)
                        ->merge($relatedDocumentsStaging)
                        ->merge($relatedDocumentsNew)
                        ->unique(); // Hapus duplikasi dokumen

                    // Simpan data ke dalam tabel BarcodeDamaged
                    foreach ($relatedDocuments as $document) {
                        BarcodeDamaged::updateOrCreate(
                            [
                                'code_document' => $document, // Simpan dokumen terkait barcode
                                'old_barcode_product' => $barcode,
                            ],
                            [
                                'occurrences' => $count, // Menyimpan jumlah duplikasi
                            ]
                        );
                    }
                }

                // Mengembalikan respon dengan data barcode duplikat
                return response()->json([
                    'status' => 'success',
                    'message' => 'Barcode duplikat berhasil diproses.',
                    'data' => $duplicateBarcodes,
                ]);
            }

            // Jika tidak ada barcode duplikat
            return response()->json([
                'status' => 'success',
                'message' => 'Tidak ada barcode duplikat yang ditemukan.',
                'data' => [],
            ]);
        } catch (\Exception $e) {
            // Mengembalikan respon error
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat memproses data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function findDifferenceTable(Request $request)
    {
        // Validasi input dokumen dari request
        $documents = $request->input('documents'); // Mengambil array dokumen dari request

        if (is_null($documents) || !is_array($documents)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter documents harus berupa array.',
            ], 400);
        }

        // Memperpanjang waktu eksekusi dan batas memori
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            // Mengambil semua data barcode berdasarkan kode dokumen dari semua tabel
            $barcodesOld = Product_old::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesApprove = ProductApprove::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesStaging = StagingProduct::whereIn('code_document', $documents)->pluck('old_barcode_product');
            $barcodesNew = New_product::whereIn('code_document', $documents)->pluck('old_barcode_product');

            // Gabungkan semua barcode sebagai patokan
            $mergedBarcodes = $barcodesOld->merge($barcodesApprove)->merge($barcodesStaging)->merge($barcodesNew);

            // Barcode baru dari dokumen tertentu sebagai perbandingan
            $newBarcodes = Product_old::where('code_document', '0010/11/2024')->pluck('old_barcode_product');

            // Cari perbedaan antara barcode baru dengan barcode gabungan (patokan)
            $diff = $newBarcodes->diff($mergedBarcodes);

            // Jika tidak ada perbedaan ditemukan
            if ($diff->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Semua barcode sudah ada di patokan data.',
                    'data' => [],
                ]);
            }

            // Mengembalikan barcode yang tidak ada dalam patokan
            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil menemukan barcode yang tidak ada di patokan data.',
                'data' => $diff->values(), // Reset index untuk hasil yang lebih rapi
            ]);
        } catch (\Exception $e) {
            // Mengembalikan respon error
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat memproses data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
