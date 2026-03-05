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
        // 1. Tambahkan validasi category_id
        $validator = Validator::make($request->all(), [
            'bulky_document_id' => 'required|integer|exists:bulky_documents,id',
            'category_id'       => 'required|exists:categories,id', // Kategori sekarang wajib dipilih
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, 'Validation error', $validator->errors()))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $user = auth()->user();

            $bulkyDocument = BulkyDocument::where('id', $request['bulky_document_id'])
                ->where('status_bulky', 'proses')
                ->first();

            if (!$bulkyDocument) {
                DB::rollBack();
                return (new ResponseResource(false, 'Bulky document tidak ditemukan atau sudah done', null))
                    ->response()->setStatusCode(404);
            }

            $category = \App\Models\Category::find($request->category_id);

            $username = strtolower(substr($user->username, 0, 3));

            $barcode = barcodeBag($user->id);
            if (!$barcode) {
                DB::rollBack();
                return (new ResponseResource(false, "Gagal membuat barcode", null))->response()->setStatusCode(500);
            }

            $activeBag = BagProducts::where('bulky_document_id', $bulkyDocument->id)
                ->where('status', 'process')
                ->where('user_id', $user->id)
                ->where('name_bag', 'like', $username . '-%')
                ->first();

            if ($activeBag) {
                $activeBag->update(['status' => 'done']);
            }

            $lastBag = BagProducts::where('bulky_document_id', $bulkyDocument->id)
                ->where('user_id', $user->id)
                ->where('name_bag', 'like', $username . '-%')
                ->orderByDesc('id')
                ->first();

            $nextNumber = 1;
            if ($lastBag && preg_match('/^' . $username . '\-(\d+)$/', $lastBag->name_bag, $matches)) {
                $nextNumber = intval($matches[1]) + 1;
            }

            $name_bag = $username . '-' . $nextNumber;

            $addNewBag = BagProducts::create([
                'user_id'           => $user->id,
                'bulky_document_id' => $bulkyDocument->id,
                'category_id'       => $category->id,              
                'category_bag'      => $category->name_category, 
                'total_product'     => 0,
                'status'            => 'process',
                'name_bag'          => $name_bag,
                'barcode_bag'       => $barcode
            ]);

            DB::commit();
            return new ResponseResource(true, "Berhasil membuat karung baru", $addNewBag);

        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan sistem: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
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
        $userId = auth()->id();

        $bulkyDocument = BulkyDocument::where('status_bulky', 'proses')
            ->where('id', $bagProducts->bulky_document_id)
            ->first();
        if (!$bulkyDocument) {
            DB::rollBack();
            return (new ResponseResource(false, 'Bulky document tidak ditemukan atau sudah selesai', null))
                ->response()->setStatusCode(404);
        }

        $bags = BagProducts::select('id', 'status')
            ->where('bulky_document_id', $bagProducts->bulky_document_id)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();

        if ($bags->count() > 1 && $bags->first()->id == $bagProducts->id) {
            // Ambil bag sebelumnya
            $bagBeforeLast = $bags->get(1); // index ke-1 adalah sebelum terakhir
            if ($bagBeforeLast) {
                $bagBeforeLast->status = 'process';
                $bagBeforeLast->save();
            }
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
