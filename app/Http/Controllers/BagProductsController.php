<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Bundle;
use App\Models\BulkySale;
use App\Models\BagProducts;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\BulkyDocument;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;

class BagProductsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $q        = $request->input('q');
        $bagId    = $request->query('bag_id');
        $docId    = $request->input('id');
        $userId   = auth()->id();
        $perPage  = $request->input('per_page', 10);

        $bags = BagProducts::select('id', 'barcode_bag', 'name_bag', 'total_product')->latest()->where('bulky_document_id', $docId)
            ->where('user_id', $userId)->get();

        if ($bagId) {
            $bagProduct = BagProducts::where('bulky_document_id', $docId)
                ->where('user_id', $userId)->where('id', $bagId)
                ->first();
        } else {
            $bagProduct = BagProducts::where('bulky_document_id', $docId)
                ->where('user_id', $userId)
                ->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'process');
                })
                ->first();
        }

        $bulkyDocument = BulkyDocument::find($docId);

        if (!$bagProduct) {
            $bulkyDocument->bulky_sales = $bulkyDocument->bulkySales;
            return new ResponseResource(true, 'List of bag products', [
                'bulky_document' => $bulkyDocument,
                'bag_product' => null,
            ]);
        }
        $columns = Schema::getColumnListing('bulky_sales');
        $columns = array_diff($columns, ['created_at', 'updated_at']);

        $bulkySales = BulkySale::select($columns)->where('bag_product_id', $bagProduct->id)
            ->when($q, function ($query) use ($q) {
                $query->where('barcode_bulky_sale', 'like', "%{$q}%")
                    ->orWhere('name_product_bulky_sale', 'like', "%{$q}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $bagProduct->bulky_sales       = $bulkySales->items();
        $bagProduct->total_bulky_sales = $bulkySales->total();
        $bagProduct->bulky_sales_meta  = [
            'current_page' => $bulkySales->currentPage(),
            'last_page'    => $bulkySales->lastPage(),
            'per_page'     => $bulkySales->perPage(),
            'total'        => $bulkySales->total(),
        ];

        return new ResponseResource(true, 'Detail bag product with paginated items', [
            'ids' => $bags,
            'bulky_document' => $bulkyDocument,
            'bag_product'    => $bagProduct
        ]);
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
        DB::beginTransaction();
        $user = auth()->id();

        $validator = Validator::make($request->all(), [
            // 'bag_id' => 'required|integer|exists:bag_products,id',
            'bulky_document_id' => 'required|integer|exists:bulky_documents,id',
            // 'name_bag' => 'required|string|max:255',
            // 'category_bag' => 'required|string|max:255',

        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, 'Validation error', $validator->errors()))
                ->response()->setStatusCode(422);
        }

        $bulkyDocument = BulkyDocument::where('id', $request['bulky_document_id'])
            ->where('status_bulky', 'proses')->first();

        $bagNameFormat = User::where('id', $user)->first();
        $username = strtolower(substr($bagNameFormat->username, 0, 3));

        if ($bulkyDocument) {
            $bagProduct = BagProducts::latest()->where('bulky_document_id', $bulkyDocument->id)
                ->where('status', 'process')->where('user_id', $user)
                ->where('name_bag', 'like', $username . '-%')
                ->first();

            $barcode = barcodeBag($user);
            if (!$barcode) {
                DB::rollBack();
                return (new ResponseResource(false, "gagal membuat barcode", null))->response()->setStatusCode(500);
            }
            if ($bagProduct) {
                $bagProduct->update(['status' => 'done']);

                if ($bagProduct && preg_match('/^' . $username . '\-(\d+)$/', $bagProduct->name_bag, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                } else {
                    $nextNumber = 1;
                }

                $name_bag = $username . '-' . $nextNumber;

                $addNewBag = BagProducts::create([
                    'user_id' => $user,
                    'bulky_document_id' => $bulkyDocument->id,
                    'total_product' => 0,
                    'status' => 'process',
                    'name_bag' => $name_bag,
                    'category_bag' => null,
                    'barcode_bag' => $barcode

                ]);
                if (!$addNewBag) {
                    DB::rollBack();
                    return (new ResponseResource(false, "gagal membuat karung product", $addNewBag))->response()->setStatusCode(500);
                }
                DB::commit();
                return new ResponseResource(true, "berhasil menambah karung baru", $addNewBag);
            } else {

                $lastBag = BagProducts::where('bulky_document_id', $bulkyDocument->id)
                    ->where('user_id', $user)
                    ->where('name_bag', 'like', $username . '-%')
                    ->orderByDesc('id')
                    ->first();

                if ($lastBag && preg_match('/^' . $username . '\-(\d+)$/', $lastBag->name_bag, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                } else {
                    $nextNumber = 1;
                }

                $name_bag = $username . '-' . $nextNumber;

                $addNewBag = BagProducts::create([
                    'user_id' => $user,
                    'bulky_document_id' => $bulkyDocument->id,
                    'total_product' => 0,
                    'status' => 'process',
                    'name_bag' => $name_bag,
                    'category_bag' => $request['category_bag'] ?? null,
                    'barcode_bag' => $barcode
                ]);

                if (!$addNewBag) {
                    DB::rollBack();
                    return (new ResponseResource(false, "gagal membuat karung product", $addNewBag))->response()->setStatusCode(500);
                }
                DB::commit();
                return new ResponseResource(true, "berhasil membuat karung baru", $addNewBag);
            }
        } else {
            DB::rollBack();
            return (new ResponseResource(false, 'Bulky document tidak ditemukan atau sudah done', null))
                ->response()->setStatusCode(404);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, BagProducts $bagProducts)
    {
        $query = $request->input('q');
        $bulkySales = BulkySale::where('bag_product_id', $bagProducts->id);

        $bagProducts->price = BulkySale::where('bag_product_id', $bagProducts->id)
            ->sum('after_price_bulky_sale');

        if ($query) {
            $bulkySales->where(function ($q) use ($query) {
                $q->where('barcode_bulky_sale', 'like', "%{$query}%")
                    ->orWhere('name_product_bulky_sale', 'like', "%{$query}%");
            });
        }
        $bulkySales = $bulkySales->orderBy('created_at', 'desc')->paginate(15);

        // Ambil items dari hasil paginasi
        $categoryCounts = collect($bulkySales->items())
            ->groupBy('product_category_bulky_sale')
            ->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => count($items)
                ];
            })
            ->values();

        return new ResponseResource(true, 'Detail Bag Product', [
            "bag_product" => $bagProducts,
            'category_counts' => $categoryCounts,
            'bulky_sales' => $bulkySales
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BagProducts $bagProducts)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BagProducts $bagProducts)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BagProducts $bagProducts)
    {
        DB::beginTransaction();
        if ($bagProducts->status !== 'process') {
            DB::rollBack();
            return (new ResponseResource(false, 'Hanya bag product dengan status proses yang dapat dihapus', null))
                ->response()->setStatusCode(400);
        }

        $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')
            ->where('id', $bagProducts->bulky_document_id)
            ->first();
        if (!$bulkyDocument) {
            DB::rollBack();
            return (new ResponseResource(false, 'Bulky document tidak ditemukan atau sudah selesai', null))
                ->response()->setStatusCode(404);
        }

        $products = BulkySale::where('bag_product_id', $bagProducts->id)->get();
        $oldPriceBulkySale = $products->sum('old_price_bulky_sale');
        $afterPriceBulkySale = $products->sum('after_price_bulky_sale');
        $totalProduct = $products->count();

        $bulkyDocument->total_old_price_bulky = $bulkyDocument->total_old_price_bulky - $oldPriceBulkySale;
        $bulkyDocument->after_price_bulky -= $afterPriceBulkySale;
        $bulkyDocument->total_product_bulky -= $totalProduct;
        $bulkyDocument->save();
        
        foreach ($products as $product) {
            $models = [
                'new_product' => New_product::where('new_barcode_product', $product->barcode_bulky_sale)->first(),
                'staging_product' => StagingProduct::where('new_barcode_product', $product->barcode_bulky_sale)->first(),
                'bundle_product' => Bundle::where('barcode_bundle', $product->barcode_bulky_sale)->first(),
            ];
            foreach ($models as $type => $model) {
                if ($model) {
                    match ($type) {
                        'new_product', 'staging_product' => $model->update(['new_status_product' => $product->status_product_before]),
                        'bundle_product' => $model->update(['product_status' => $product->status_product_before]),
                    };
                    break; // keluar dari loop setelah update pada yang pertama
                }
            }
        }

        $bagProducts->delete();
        DB::commit();
        return new ResponseResource(true, 'Berhasil menghapus bag product', $bagProducts);
    }
}
