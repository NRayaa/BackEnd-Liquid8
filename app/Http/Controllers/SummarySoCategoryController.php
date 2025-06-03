<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\StagingProduct;
use App\Models\SummarySoCategory;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;

class SummarySoCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $searchQuery = $request->query('q') ?? null;

        $query = SummarySoCategory::query();

        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('type', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('start_date', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('end_date', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        $summarySoCategories = $query->paginate(10);

        return new ResponseResource(true, "List of SO categories", $summarySoCategories);
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
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(SummarySoCategory $summarySoCategory)
    {
        if (!$summarySoCategory) {
            return (new ResponseResource(
                false,
                "Summary SO Category not found",
                null
            ))->response()->setStatusCode(404);
        }
        return new ResponseResource(true, "Summary SO Category details", $summarySoCategory);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SummarySoCategory $summarySoCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SummarySoCategory $summarySoCategory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SummarySoCategory $summarySoCategory)
    {
        //
    }

    public function startSo(Request $request)
    {
        DB::beginTransaction();
        try {

            $checkSummary = SummarySoCategory::where('type', 'process')->where('end_date', null)->first();
            if ($checkSummary) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "SO process already started",
                    null
                ))->response()->setStatusCode(422);
            }
            $newPeriod = SummarySoCategory::create([
                'type' => 'process',
                'product_inventory' => 0,
                'product_staging' => 0,
                'product_bundle' => 0,
                'product_damaged' => 0,
                'product_abnormal' => 0,
                'product_lost' => 0,
                'product_addition' => 0,
                'start_date' => Carbon::now('Asia/Jakarta'),
                'end_date' => null,
            ]);

            DB::commit();
            return new ResponseResource(true, "SO process started successfully", $newPeriod);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function stopSo(Request $request)
    {
        DB::beginTransaction();
        try {
            $activePeriod = SummarySoCategory::where('type', 'process')->where('end_date', null)->first();
            if (!$activePeriod) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "No active SO period found",
                    null
                ))->response()->setStatusCode(422);
            }

            $activePeriod->update([
                'end_date' => Carbon::now('Asia/Jakarta'),
                'type' => 'done',
            ]);

            DB::commit();
            return new ResponseResource(true, "SO process stopped successfully", $activePeriod);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function searchSo(Request $request)
    {
        $searchQuery = $request->query('q') ?? null;

        $newProductsQuery = New_product::whereNull('is_so')
            ->where('new_status_product', '!=', 'sale')
            ->whereNull('new_tag_product')
            ->select(
                'new_barcode_product as barcode',
                'new_name_product as name',
                'new_category_product as category',
                'created_at as created_date',
                DB::raw("
            CASE 
                WHEN JSON_TYPE(JSON_EXTRACT(new_quality, '$.damaged')) != 'NULL' THEN 'damaged'
                WHEN JSON_TYPE(JSON_EXTRACT(new_quality, '$.abnormal')) != 'NULL' THEN 'abnormal'
                ELSE 'inventory'
            END as type
        ")
            );

        $stagingProductsQuery = StagingProduct::whereNull('is_so')
            ->where('new_status_product', '!=', 'sale')
            ->whereNotNull('new_category_product')
            ->whereNull('new_tag_product')
            ->select(
                'new_barcode_product as barcode',
                'new_name_product as name',
                'new_category_product as category',
                'created_at as created_date',
                DB::raw("'staging' as type")
            );

        if ($searchQuery) {
            $newProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });

            $stagingProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        $bundleQuery = Bundle::whereNot('type', 'type2')
            ->select(
                'barcode_bundle as barcode',
                'name_bundle as name',
                'category',
                'created_at as created_date',
                DB::raw("'bundle' as type")
            );

        if ($searchQuery) {
            $bundleQuery->where(function ($query) use ($searchQuery) {
                $query->where('barcode_bundle', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('name_bundle', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        $products = $newProductsQuery->union($stagingProductsQuery)->union($bundleQuery)
            ->paginate(10);

        if ($searchQuery && $products->total() == 0) {
            // Buat data lost
            $lostData = [[
                'barcode' => $searchQuery,
                'name' => 'Product not found',
                'category' => null,
                'created_date' => now()->toDateTimeString(),
                'type' => 'lost'
            ]];

            // Buat custom paginator
            $paginator = new LengthAwarePaginator(
                $lostData,                              // items
                1,                                      // total
                10,                                     // perPage
                $request->get('page', 1),               // currentPage
                ['path' => $request->url()]            // options
            );

            // Set query parameters
            $paginator->appends($request->query());

            $resource = new ResponseResource(true, "Product not found", $paginator);
            return $resource->response();
        }

        $resource = new ResponseResource(true, "list data product", $products);
        return $resource->response();
    }

    public function update_check(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'nullable|string|in:inventory,staging,bundle,damaged,abnormal,lost,manual',
                'barcode' => 'required|string',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "Validation error",
                    $validator->errors()
                ))->response()->setStatusCode(422);
            }

            // Cek apakah ada periode SO yang aktif
            $activePeriod = SummarySoCategory::where('type', 'process')->first();
            if (!$activePeriod) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "No active SO period found",
                    null
                ))->response()->setStatusCode(422);
            }


            // Cek apakah produk sudah ada di SO (baik inventory, bundle, staging, damaged, abnormal, addition)
            $inventory = New_product::where('new_barcode_product', $request['barcode'])
                ->whereNull('is_so')
                ->first();
            $bundle = Bundle::where('barcode_bundle', $request['barcode'])
                ->whereNull('is_so')
                ->first();
            $staging = StagingProduct::where('new_barcode_product', $request['barcode'])
                ->whereNull('is_so')
                ->first();
            $damaged = New_product::where('new_barcode_product', $request['barcode'])
                ->whereNull('is_so')
                ->whereNotNull('new_quality->damaged')
                ->first();
            $abnormal = New_product::where('new_barcode_product', $request['barcode'])
                ->whereNull('is_so')
                ->whereNotNull('new_quality->abnormal')
                ->first();

            if ($request['type'] == 'lost') {
                // $product = New_product::where('new_barcode_product', $request['barcode'])
                //     ->whereNull('is_so')
                //     ->first();

                // if ($product) {
                //     $product->update([
                //         'is_so' => 'check',
                //         'user_so' => auth()->user()->id,
                //         // 'so_period_id' => $activePeriod->id,
                //     ]);

                // }
                $activePeriod->increment('product_lost');
                DB::commit();
                return new ResponseResource(true, "Product checked lost successfully", $request['barcode']);
            } else if ($request['type'] == 'manual') {

                if ($inventory) {

                    $inventory->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_inventory'); // Fix: gunakan increment

                } else if ($bundle) {

                    $bundle->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_bundle');
                } else if ($staging) {
                    $staging->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_staging');
                } else if ($damaged) {
                    $damaged->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_damaged');
                } else if ($abnormal) {
                    $abnormal->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_abnormal');
                } else {
                    DB::rollBack();
                    return (new ResponseResource(
                        false,
                        "Product not found in any category",
                        null
                    ))->response()->setStatusCode(404);
                }
            } else if ($request['type'] !== 'manual' && $request['type'] !== 'lost') {
                if ($inventory) {

                    $inventory->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_inventory'); // Fix: gunakan increment

                } else if ($bundle) {

                    $bundle->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_bundle');
                } else if ($staging) {
                    $staging->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_staging');
                } else if ($damaged) {
                    $damaged->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);

                    $activePeriod->increment('product_damaged');
                } else if ($abnormal) {
                    $abnormal->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                        // 'so_period_id' => $activePeriod->id,
                    ]);
                }
            }

            DB::commit();
            return new ResponseResource(true, "Product checked successfully", $request['barcode']);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function filterSoUser(Request $request)
    {
        $userid = auth()->user()->id;
        $searchQuery = $request->query('q') ?? null;

        $newProductsQuery = New_product::where(function ($query) {
            $query->where('is_so', 'check')
                ->orWhere('is_so', 'addition');
        })
            ->where('user_so', $userid)
            ->whereNull('new_tag_product')
            ->select(
                'new_barcode_product as barcode',
                'new_name_product as name',
                DB::raw("
            CASE 
                WHEN JSON_TYPE(JSON_EXTRACT(new_quality, '$.damaged')) != 'NULL' THEN 'damaged'
                WHEN JSON_TYPE(JSON_EXTRACT(new_quality, '$.abnormal')) != 'NULL' THEN 'abnormal'
                WHEN is_so = 'addition' THEN 'addition'
                ELSE 'inventory'
            END as type
        ")
            );

        $stagingProductsQuery = StagingProduct::where('is_so', 'check')
            ->where('user_so', $userid)
            ->whereNull('new_tag_product')
            ->select(
                'new_barcode_product as barcode',
                'new_name_product as name',
                DB::raw("'staging' as type")
            );

        if ($searchQuery) {
            $newProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });

            $stagingProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        $bundleQuery = Bundle::whereNot('type', 'type2')
            ->select(
                'barcode_bundle as barcode',
                'name_bundle as name',
                DB::raw("'bundle' as type")
            );

        if ($searchQuery) {
            $bundleQuery->where(function ($query) use ($searchQuery) {
                $query->where('barcode_bundle', 'LIKE', '%' . $searchQuery . '%')
                    ->orWhere('name_bundle', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        $products = $newProductsQuery->union($stagingProductsQuery)->union($bundleQuery)
            ->paginate(10);

        $resource = new ResponseResource(true, "list data product", $products);
        return $resource->response();
    }
}
