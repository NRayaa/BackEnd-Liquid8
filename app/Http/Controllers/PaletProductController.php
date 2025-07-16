<?php

namespace App\Http\Controllers;

use App\Models\Palet;
use App\Models\PaletFilter;
use App\Models\PaletProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use Carbon\Carbon;


class PaletProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $product_palets = PaletProduct::latest()->paginate(100);
        return new ResponseResource(true, "list product palet", $product_palets);
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
        try {
            $userId = auth()->id();
            $product_filters = PaletFilter::where('user_id', $userId)->get();

            if ($product_filters->isEmpty()) {
                return new ResponseResource(false, "Tidak ada produk filter yang tersedia saat ini", $product_filters);
            }

            $palet = Palet::create([
                'name_palet' => $request->name_palet,
                'category_palet' => $request->category_palet,
                'total_price_palet' => $request->total_price_palet,
                'total_product_palet' => $request->total_product_palet,
                'palet_barcode' => $request->palet_barcode,
            ]);


            $insertData = $product_filters->map(function ($product) use ($palet, $userId) {
                return [
                    'palet_id' => $palet->id,
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => $product->new_status_product,
                    'new_quality' => $product->new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'new_discount' => $product->new_discount,
                    'display_price' => $product->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'type' => $product->type,
                    'user_id' => $userId
                ];
            })->toArray();

            PaletProduct::insert($insertData);

            PaletFilter::where('user_id', $userId)->delete();

            DB::commit();
            return new ResponseResource(true, "Palet berhasil dibuat", $palet);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat palet: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke palet', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource. 
     */
    public function show(PaletProduct $paletProduct)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PaletProduct $paletProduct)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PaletProduct $paletProduct)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaletProduct $paletProduct)
    {
        $userId = auth()->id();
        DB::beginTransaction();
        try {
            New_product::create([
                'code_document' => $paletProduct->code_document,
                'old_barcode_product' => $paletProduct->old_barcode_product,
                'new_barcode_product' => $paletProduct->new_barcode_product,
                'new_name_product' => $paletProduct->new_name_product,
                'new_quantity_product' => $paletProduct->new_quantity_product,
                'new_price_product' => $paletProduct->new_price_product,
                'old_price_product' => $paletProduct->old_price_product,
                'new_date_in_product' => $paletProduct->new_date_in_product,
                'new_status_product' => $paletProduct->new_status_product,
                'new_quality' => $paletProduct->new_quality,
                'new_category_product' => $paletProduct->new_category_product,
                'new_tag_product' => $paletProduct->new_tag_product,
                'new_discount' => $paletProduct->new_discount,
                'display_price' => $paletProduct->display_price,
                'type' => $paletProduct->type,
                'user_id' => $userId
            ]);


            $palet = Palet::findOrFail($paletProduct->palet_id);
            $paletProductAll = PaletProduct::where('palet_id', $palet->id)->get();

            if ($palet->discount !== null && $palet->discount !== 0) {
                // Hitung total harga produk lama tanpa diskon
                $priceOldWithoutDiscount = $paletProductAll->sum('old_price_product');

                // Kurangi harga produk yang dihapus
                $countFixedPrice = $priceOldWithoutDiscount - $paletProduct->old_price_product;

                // Diskon palet
                $discountPalet = $palet->discount;
                $newTotalPricePalet = $countFixedPrice * (1 - $discountPalet / 100);

                $palet->update([
                    'total_price_palet' => $newTotalPricePalet,
                    'total_product_palet' => max($palet->total_product_palet - 1, 0),
                ]);
            } else {
                $palet->update([
                    'total_price_palet' => max($palet->total_price_palet - $paletProduct->new_price_product, 0),
                    'total_product_palet' => max($palet->total_product_palet - 1, 0),
                ]);
            }



            $paletProduct->delete();

            DB::commit();
            return new ResponseResource(true, "palet berhasil dihapus", $paletProduct);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal menghapus produk palet: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus produk palet', 'error' => $e->getMessage()], 500);
        }
    }

    public function addProductPalet(New_product $new_product, Palet $palet)
    {
        $userId = auth()->id();
        DB::beginTransaction();
        try {

            $productPalet = PaletProduct::create([
                'palet_id' => $palet->id,
                'code_document' => $new_product->code_document,
                'old_barcode_product' => $new_product->old_barcode_product,
                'new_barcode_product' => $new_product->new_barcode_product,
                'new_name_product' => $new_product->new_name_product,
                'new_quantity_product' => $new_product->new_quantity_product,
                'new_price_product' => $new_product->new_price_product,
                'old_price_product' => $new_product->old_price_product,
                'new_date_in_product' => $new_product->new_date_in_product,
                'new_status_product' => $new_product->new_status_product,
                'new_quality' => $new_product->new_quality,
                'new_category_product' => $new_product->new_category_product,
                'new_tag_product' => $new_product->new_tag_product,
                'new_discount' => $new_product->new_discount,
                'display_price' => $new_product->display_price,
                'type' => $new_product->type,
                'user_id' => $userId
            ]);

            $paletProductAll = PaletProduct::where('palet_id', $palet->id)->get();

            if ($palet->discount !== null && $palet->discount !== 0) {
                $priceOldWithoutDiscount = $paletProductAll->sum('old_price_product');

                // Diskon palet
                $discountPalet = $palet->discount;
                $newTotalPricePalet = $priceOldWithoutDiscount * (1 - $discountPalet / 100);

                $palet->update([
                    'total_price_palet' => $newTotalPricePalet,
                    'total_product_palet' => max($palet->total_product_palet + 1, 0),
                ]);
            } else {
                $palet->update([
                    'total_price_palet' => max($palet->total_price_palet + $new_product->new_price_product, 0),
                    'total_product_palet' => max($palet->total_product_palet + 1, 0),
                ]);
            }
            $new_product->delete();

            DB::commit();
            return new ResponseResource(true, "Product palet berhasil di tambahkan", $productPalet);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat palet: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke palet', 'error' => $e->getMessage()], 500);
        }
    }

    public function listFilterToBulky(Request $request, $paletId)
    {
        // Mendapatkan nilai query 'q' dari request
        $search = $request->query('q');

        // Mencari palet berdasarkan $paletId
        $palet = Palet::findOrFail($paletId);
        if (!$palet) {
            return new ResponseResource(false, "Palet tidak ditemukan", null);
        }
        $paletProducts = PaletProduct::where('palet_id', $palet->id)
            ->where('is_bulky', 'yes');
        if ($search) {
            $paletProducts = $paletProducts->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'like', "%$search%")
                    ->orWhere('old_barcode_product', 'like', "%$search%")
                    ->orWhere('new_name_product', 'like', "%$search%");
            });
        }
        $paletProducts = $paletProducts->paginate(33);
        return new ResponseResource(true, "Produk palet ditemukan", $paletProducts);
    }

    public function toFilterBulky(Request $request, $product_palet_id)
    {
        $productPalet = PaletProduct::where(function ($query) {
            $query->whereNull('is_bulky')
                ->orWhere('is_bulky', 'no');
        })->findOrFail($product_palet_id);

        $productPalet->update([
            'is_bulky' => 'yes',
        ]);

        return new ResponseResource(true, "Produk palet berhasil diubah menjadi bulky", $productPalet);
    }


    public function toUnFilterBulky(Request $request, $product_palet_id)
    {
        $productPalet = PaletProduct::where('is_bulky', 'yes')->findOrFail($product_palet_id);
        $productPalet->update([
            'is_bulky' => 'no',
        ]);
        return new ResponseResource(true, "Produk palet berhasil diubah menjadi tidak bulky", $productPalet);
    }

    public function allListFilter(Request $request)
    {
        // Mendapatkan nilai query 'q' dari request
        $search = $request->query('q');
        
        $paletProducts = PaletProduct::where('is_bulky', 'yes');
        if ($search) {
            $paletProducts = $paletProducts->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'like', "%$search%")
                    ->orWhere('old_barcode_product', 'like', "%$search%")
                    ->orWhere('new_name_product', 'like', "%$search%");
            });
        }
        $paletProducts = $paletProducts->paginate(33);
        return new ResponseResource(true, "Produk palet ditemukan", $paletProducts);
    }
}
