<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BklDocument;
use App\Models\BklItem;
use App\Models\BklProduct;
use App\Models\Destination;
use App\Services\Olsera\OlseraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BklController extends Controller
{
    public function listOlseraOutgoing(Request $request)
    {
        try {
            $destinations = Destination::where('is_olsera_integrated', true)->get();
            $allDraftDocuments = collect();

            foreach ($destinations as $destination) {
                try {
                    $olseraService = new OlseraService($destination);
                    $response = $olseraService->getOutgoingStockList();

                    if ($response['success']) {
                        $items = $response['data']['data'] ?? [];
                        $drafts = collect($items)->filter(function ($item) {
                            return isset($item['status']) && $item['status'] === 'D';
                        })->map(function ($item) use ($destination) {
                            $item['destination_id'] = $destination->id;
                            $item['shop_name'] = $destination->shop_name;
                            return $item;
                        });
                        $allDraftDocuments = $allDraftDocuments->merge($drafts);
                    }
                } catch (\Exception $e) {
                    Log::error("Error Outgoing {$destination->shop_name}: " . $e->getMessage());
                }
            }

            $sortedDocuments = $allDraftDocuments->sortByDesc('date')->values();
            return new ResponseResource(true, "List Antrean Retur Olsera", $sortedDocuments);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }


    public function detailOlseraOutgoing(Request $request, $id)
    {
        try {
            $request->validate(['destination_id' => 'required|exists:destinations,id']);

            $destination = Destination::find($request->destination_id);
            $olseraService = new OlseraService($destination);
            $response = $olseraService->getStockInOutDetail(['id' => $id]);

            if (!$response['success']) {
                return (new ResponseResource(false, 'Gagal menarik detail Olsera: ' . $response['message'], null))
                    ->response()->setStatusCode(500);
            }

            $olseraData = $response['data'];
            $items = $olseraData['data']['items'] ?? [];

            $total24K = 0;
            $total12K = 0;

            foreach ($items as $item) {
                $name = strtoupper($item['product_name']);
                $qty = (int) $item['qty'];

                if (str_contains($name, '24')) {
                    $total24K += $qty;
                } elseif (str_contains($name, '12')) {
                    $total12K += $qty;
                }
            }

            $olseraData['data']['summary_expected_qty'] = [
                'total_qty_24K' => $total24K,
                'total_qty_12K' => $total12K,
                'total_qty' => $total24K + $total12K
            ];

            return new ResponseResource(true, "Detail Retur Olsera", $olseraData);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function processOlseraOutgoing(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'destination_id' => 'required|exists:destinations,id',
                'olsera_document_id' => 'required',
                'olsera_document_code' => 'required|string',
                'damage_qty' => 'nullable|integer|min:0',
                'colors' => 'nullable|array',
                'colors.*.color_tag_id' => 'required_with:colors|exists:color_tags,id',
                'colors.*.qty' => 'required_with:colors|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'message' => $validator->errors()], 422);
            }

            $destination = Destination::find($request->destination_id);
            $olseraService = new OlseraService($destination);

            $detailResponse = $olseraService->getStockInOutDetail(['id' => $request->olsera_document_id]);
            if (!$detailResponse['success']) throw new \Exception("Gagal mengambil detail dokumen dari Olsera.");

            $olseraData = $detailResponse['data']['data'] ?? [];
            if (isset($olseraData['status']) && $olseraData['status'] === 'P') {
                throw new \InvalidArgumentException("Validasi Gagal: Dokumen Olsera ini sudah berstatus Posted (P).");
            }

            $olseraQty = ['24' => 0, '12' => 0];
            $olseraProductNames = ['24' => 'BKL 24K', '12' => 'BKL 12K'];

            foreach ($olseraData['items'] ?? [] as $oItem) {
                $oName = strtoupper($oItem['product_name']);
                if (str_contains($oName, '24')) {
                    $olseraQty['24'] += $oItem['qty'];
                    $olseraProductNames['24'] = $oItem['product_name'];
                } elseif (str_contains($oName, '12')) {
                    $olseraQty['12'] += $oItem['qty'];
                    $olseraProductNames['12'] = $oItem['product_name'];
                }
            }

            $validColors24 = ['merah', 'biru', 'big'];
            $validColors12 = ['kuning', 'hijau', 'small'];

            $inputColorQty = ['24' => 0, '12' => 0];
            $mappedColors = ['24' => [], '12' => []];

            if ($request->has('colors')) {
                foreach ($request->colors as $colorData) {
                    $colorRow = DB::table('color_tags')->where('id', $colorData['color_tag_id'])->first();
                    if (!$colorRow) throw new \InvalidArgumentException("Validasi Gagal: ID Warna tidak ditemukan.");

                    $colorName = strtolower(trim($colorRow->name_color));
                    $qty = $colorData['qty'];

                    if (in_array($colorName, $validColors24)) {
                        $inputColorQty['24'] += $qty;
                        $mappedColors['24'][] = ['tag' => $colorName, 'qty' => $qty];
                    } elseif (in_array($colorName, $validColors12)) {
                        $inputColorQty['12'] += $qty;
                        $mappedColors['12'][] = ['tag' => $colorName, 'qty' => $qty];
                    } else {
                        throw new \InvalidArgumentException("Validasi Gagal: Warna '{$colorName}' tidak dikenal dalam Kasta WMS (24K/12K).");
                    }
                }
            }

            $unaccounted = [
                '24' => $olseraQty['24'] - $inputColorQty['24'],
                '12' => $olseraQty['12'] - $inputColorQty['12']
            ];

            if ($unaccounted['24'] < 0 || $unaccounted['12'] < 0) {
                throw new \InvalidArgumentException("Validasi Gagal: Jumlah QC Warna melebihi data stok Olsera.");
            }

            $totalUnaccounted = $unaccounted['24'] + $unaccounted['12'];
            $userSubmittedDamage = $request->damage_qty ?? 0;

            if ($userSubmittedDamage > $totalUnaccounted) {
                throw new \InvalidArgumentException("Validasi Gagal: Jumlah Damaged ({$userSubmittedDamage}) melebihi sisa barang yang belum di-QC ({$totalUnaccounted}).");
            }

            $totalLost = $totalUnaccounted - $userSubmittedDamage;

            $allocatedDamage = ['24' => 0, '12' => 0];
            $remainingDamage = $userSubmittedDamage;

            foreach (['24', '12'] as $cat) {
                $needed = $unaccounted[$cat];
                if ($needed > 0) {
                    $take = min($needed, $remainingDamage);
                    $allocatedDamage[$cat] = $take;
                    $remainingDamage -= $take;
                }
            }

            $updateResponse = $olseraService->updateStatusStockInOut([
                'pk' => $request->olsera_document_id,
                'status' => 'P'
            ]);

            if (!$updateResponse['success']) throw new \Exception("Gagal Publish Olsera: " . $updateResponse['message']);

            $document = BklDocument::firstOrCreate(
                ['code_document_bkl' => $request->olsera_document_code],
                ['status' => 'done', 'user_id' => $user->id]
            );

            // Catat History Colors
            if ($request->has('colors')) {
                foreach ($request->colors as $colorData) {
                    BklItem::create([
                        'bkl_document_id' => $document->id,
                        'type' => 'in',
                        'qty' => $colorData['qty'],
                        'color_tag_id' => $colorData['color_tag_id'],
                        'is_damaged' => null,
                        'is_lost' => null
                    ]);
                }
            }

            // Catat History Damaged
            if ($userSubmittedDamage > 0) {
                BklItem::create([
                    'bkl_document_id' => $document->id,
                    'type' => 'in',
                    'qty' => $userSubmittedDamage,
                    'color_tag_id' => null,
                    'is_damaged' => true,
                    'is_lost' => null
                ]);
            }

            // Catat History Lost (Selisih Barang)
            if ($totalLost > 0) {
                BklItem::create([
                    'bkl_document_id' => $document->id,
                    'type' => 'in',
                    'qty' => $totalLost,
                    'color_tag_id' => null,
                    'is_damaged' => null,
                    'is_lost' => true
                ]);
            }

            $tanggalMasuk = now()->format('Y-m-d');

            foreach (['24', '12'] as $cat) {
                if ($olseraQty[$cat] == 0) continue;

                $productName = $olseraProductNames[$cat];
                $priceValue = ($cat === '24') ? 24000 : 12000;

                foreach ($mappedColors[$cat] as $c) {
                    $colorTagCapitalized = ucfirst($c['tag']);
                    for ($i = 0; $i < $c['qty']; $i++) {
                        BklProduct::create([
                            'code_document' => $request->olsera_document_code,
                            'old_barcode_product' => 'BKL-' . strtoupper(Str::random(10)),
                            'new_barcode_product' => 'BKL-' . strtoupper(Str::random(10)),
                            'new_name_product' => $productName,
                            'new_quantity_product' => 1,
                            'new_status_product' => 'display',
                            'new_quality' => ['lolos' => 'lolos', 'damaged' => null, 'abnormal' => null],
                            'new_tag_product' => $colorTagCapitalized,
                            'new_date_in_product' => $tanggalMasuk,
                            'new_price_product' => $priceValue,
                            'old_price_product' => $priceValue,
                            'display_price' => $priceValue,
                            'actual_old_price_product' => $priceValue
                        ]);
                    }
                }

                for ($i = 0; $i < $allocatedDamage[$cat]; $i++) {
                    BklProduct::create([
                        'code_document' => $request->olsera_document_code,
                        'old_barcode_product' => 'BKL-' . strtoupper(Str::random(10)),
                        'new_barcode_product' => 'BKL-' . strtoupper(Str::random(10)),
                        'new_name_product' => $productName,
                        'new_quantity_product' => 1,
                        'new_status_product' => 'display',
                        'new_quality' => ['lolos' => null, 'damaged' => 'damaged', 'abnormal' => null],
                        'new_tag_product' => null,
                        'new_date_in_product' => $tanggalMasuk,
                        'new_price_product' => $priceValue,
                        'old_price_product' => $priceValue,
                        'display_price' => $priceValue,
                        'actual_old_price_product' => $priceValue
                    ]);
                }
            }

            if (function_exists('logUserAction')) logUserAction($request, $user, 'QC BKL', "Proses QC Olsera Outgoing: {$request->olsera_document_code}");

            DB::commit();
            return new ResponseResource(true, "QC Selesai! History tercatat & Produk BKL Auto-Mapping berhasil dibuat.", $document->load('items'));
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return (new ResponseResource(false, $e->getMessage(), null))->response()->setStatusCode(422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Proses QC BKL Error (Sistem): " . $e->getMessage());
            return (new ResponseResource(false, 'Terjadi kesalahan sistem: ' . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }

    public function listBklDocument(Request $request)
    {
        $query = BklDocument::query()->where('status', 'done');

        if ($request->has('q')) {
            $search = $request->q;
            $query->where('code_document_bkl', 'like', '%' . $search . '%');
        }
        $documents = $query->latest()->paginate(10);
        return new ResponseResource(true, "Riwayat BKL Documents", $documents);
    }

    public function detailBklDocument($id)
    {
        $document = BklDocument::with('items.colorTag')->find($id);

        if (!$document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan'], 404);
        }

        return new ResponseResource(true, "Detail BKL Document", $document);
    }

    public function listBklProduct(Request $request)
    {
        $querySearch = $request->input('q');
        $page = $request->input('page', 1);
        $perPage = 50;

        try {
            $baseQuery = BklProduct::whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->whereNull('is_so')
                ->whereJsonContains('new_quality->lolos', 'lolos')
                ->where(function ($q) {
                    $q->where('new_status_product', 'display')
                        ->orWhere('new_status_product', 'expired');
                })
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'type1');
                })
                ->when($querySearch, function ($q) use ($querySearch) {
                    $q->where(function ($subQuery) use ($querySearch) {
                        $subQuery->where('new_tag_product', 'LIKE', '%' . $querySearch . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $querySearch . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $querySearch . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $querySearch . '%');
                    });
                });

            $allTags = (clone $baseQuery)
                ->select(
                    'new_tag_product as tag_name',
                    DB::raw('COUNT(*) as total_data'),
                    DB::raw('SUM(new_price_product) as total_price')
                )
                ->groupBy('new_tag_product')
                ->get();

            $tagSku = $allTags->filter(function ($item) {
                return stripos($item->tag_name, 'Big') !== false || stripos($item->tag_name, 'Small') !== false;
            })->values();

            $tagColor = $allTags->reject(function ($item) {
                return stripos($item->tag_name, 'Big') !== false || stripos($item->tag_name, 'Small') !== false;
            })->values();

            $totalPriceAll = $allTags->sum('total_price');

            $productsQuery = (clone $baseQuery)
                ->select(
                    'id',
                    'old_barcode_product',
                    'new_name_product',
                    'new_date_in_product',
                    'new_status_product',
                    'new_tag_product',
                    'new_price_product'
                )
                ->latest();

            $paginated = $productsQuery->paginate($perPage, ['*'], 'page', $page);

            $items = $paginated->getCollection();

            $dataSku = $items->filter(function ($item) {
                return stripos($item->new_tag_product, 'Big') !== false || stripos($item->new_tag_product, 'Small') !== false;
            })->values();

            $dataColor = $items->reject(function ($item) {
                return stripos($item->new_tag_product, 'Big') !== false || stripos($item->new_tag_product, 'Small') !== false;
            })->values();

            return new ResponseResource(true, "list product separated by category", [
                "total_data" => $paginated->total(),
                "total_price" => $totalPriceAll,

                "tag_sku" => $tagSku,
                "tag_color" => $tagColor,

                "data_sku" => $dataSku,
                "data_color" => $dataColor,

                "pagination" => [
                    "current_page" => $paginated->currentPage(),
                    "last_page" => $paginated->lastPage(),
                    "per_page" => $paginated->perPage(),
                    "total" => $paginated->total(),
                    "next_page_url" => $paginated->nextPageUrl(),
                    "prev_page_url" => $paginated->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))
                ->response()
                ->setStatusCode(500);
        }
    }

    public function stockStatistics()
    {
        try {
            $newProductsRaw = DB::table('new_products')
                ->whereNotNull('new_tag_product')
                ->select(
                    DB::raw('LOWER(new_tag_product) as color'),
                    DB::raw('count(*) as qty'),
                    DB::raw('SUM(new_price_product) as total_value')
                )
                ->groupBy('color')
                ->get();

            $newProducts = [];
            $grandTotalNewQty = 0;
            $grandTotalNewValue = 0;

            foreach ($newProductsRaw as $row) {
                $newProducts[$row->color] = [
                    'qty' => $row->qty,
                    'total_value' => (float) $row->total_value
                ];
                $grandTotalNewQty += $row->qty;
                $grandTotalNewValue += (float) $row->total_value;
            }

            $bklProductsRaw = DB::table('bkl_products')
                ->whereNotNull('new_tag_product')
                ->select(
                    DB::raw('LOWER(new_tag_product) as color'),
                    DB::raw('count(*) as qty'),
                    DB::raw('SUM(new_price_product) as total_value')
                )
                ->groupBy('color')
                ->get();

            $bklProducts = [];
            $grandTotalBklQty = 0;
            $grandTotalBklValue = 0;

            foreach ($bklProductsRaw as $row) {
                $bklProducts[$row->color] = [
                    'qty' => $row->qty,
                    'total_value' => (float) $row->total_value
                ];
                $grandTotalBklQty += $row->qty;
                $grandTotalBklValue += (float) $row->total_value;
            }

            $olseraStock = [
                '24K' => ['qty' => 0, 'total_value' => 0],
                '12K' => ['qty' => 0, 'total_value' => 0],
                'Lainnya' => ['qty' => 0, 'total_value' => 0],
            ];
            $grandTotalOlseraQty = 0;
            $grandTotalOlseraValue = 0;

            $destinations = Destination::where('is_olsera_integrated', true)->get();

            foreach ($destinations as $destination) {
                try {
                    $olseraService = new OlseraService($destination);
                    $response = $olseraService->getProductList();

                    if ($response['success']) {
                        $items = $response['data']['data'] ?? [];

                        foreach ($items as $item) {
                            $name = strtoupper($item['name']);
                            $qty = (int) $item['stock_qty'];
                            $price = (float) ($item['sell_price'] ?? 0);
                            $totalValue = $qty * $price;

                            if (str_contains($name, '24')) {
                                $olseraStock['24K']['qty'] += $qty;
                                $olseraStock['24K']['total_value'] += $totalValue;
                            } elseif (str_contains($name, '12')) {
                                $olseraStock['12K']['qty'] += $qty;
                                $olseraStock['12K']['total_value'] += $totalValue;
                            } else {
                                $olseraStock['Lainnya']['qty'] += $qty;
                                $olseraStock['Lainnya']['total_value'] += $totalValue;
                            }

                            $grandTotalOlseraQty += $qty;
                            $grandTotalOlseraValue += $totalValue;
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Gagal menarik data produk dari {$destination->shop_name}: " . $e->getMessage());
                }
            }

            return new ResponseResource(true, "Data Statistik Stok dan Valuasi Berhasil Ditarik", [
                'wms_new_products' => [
                    'grand_total_qty' => $grandTotalNewQty,
                    'grand_total_value' => $grandTotalNewValue,
                    'details_per_color' => $newProducts
                ],
                'wms_bkl_products' => [
                    'grand_total_qty' => $grandTotalBklQty,
                    'grand_total_value' => $grandTotalBklValue,
                    'details_per_color' => $bklProducts
                ],
                'olsera_stock' => [
                    'grand_total_qty' => $grandTotalOlseraQty,
                    'grand_total_value' => $grandTotalOlseraValue,
                    'details_per_category' => $olseraStock
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Server Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
