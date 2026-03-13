<?php

namespace App\Http\Controllers;

use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\Product_Filter;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\ColorTag2;
use App\Models\ProductInput;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Validator;


class ProductFilterController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function index()
    {
        $userId = auth()->id();
        $product_filtersByUser = Product_Filter::where('user_id', $userId)->get();

        $totalNewPriceWithCategory = $product_filtersByUser->whereNotNull('new_category_product')->sum('old_price_product');
        $totalOldPriceWithoutCategory = $product_filtersByUser->whereNull('new_category_product')->sum('old_price_product');

        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory;

        $category = null;
        $color = null;
        $fixed_price = null;

        if ($totalNewPrice > 99999) {
            $category = Category::all();
        } else {
            foreach ($product_filtersByUser as $product_filter) {
                $colorTag = Color_tag::where('min_price_color', '<=', $totalNewPrice)
                    ->where('max_price_color', '>=', $totalNewPrice)
                    ->select('fixed_price_color', 'name_color')
                    ->first();

                if ($colorTag) {
                    $product_filter->new_tag_product = $colorTag->name_color;
                    $color = $colorTag->name_color;
                    $fixed_price = $colorTag->fixed_price_color;
                }
            }
        }

        $product_filters = Product_Filter::latest()->paginate(50);

        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'color' => $color,
            'fixed_price' => $fixed_price,
            'category' => $category,
            'data' => $product_filters
        ]);
    }


    /**
     * Show the form for creating a new resource.
     */

    public function create()
    {
        //
    }

    public function store(Request $request, $id)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        $source = $request->input('source');

        try {
            if ($source === 'staging') {
                $product = StagingProduct::findOrFail($id);
                if (empty($product->new_category_product)) {
                    return response()->json(['status' => false, 'message' => 'Produk Staging harus memiliki kategori'], 422);
                }
            } elseif ($source === 'display') {
                $product = New_product::findOrFail($id);
                if (empty($product->new_tag_product) || !empty($product->new_category_product)) {
                    return response()->json(['status' => false, 'message' => 'Produk Display harus memiliki tag warna dan tidak memiliki kategori'], 422);
                }
            } else {
                return response()->json(['status' => false, 'message' => 'Source produk tidak valid'], 400);
            }

            $quality = json_decode($product->new_quality, true);
            if (!isset($quality['lolos']) || $quality['lolos'] !== 'lolos') {
                return response()->json(['status' => false, 'message' => 'Quality produk tidak lolos'], 422);
            }
            if (!in_array($product->new_status_product, ['display', 'expired'])) {
                return response()->json(['status' => false, 'message' => 'Status produk harus display atau expired'], 422);
            }

            $productData = $product->toArray();
            $productData['user_id'] = $userId;
            $productData['source'] = $source;

            $productFilter = Product_Filter::create($productData);
            $product->delete();

            DB::commit();
            return new ResponseResource(true, "Berhasil menambah produk ke keranjang bundle", $productFilter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Product_Filter $product_Filter)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product_Filter $product_Filter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product_Filter $product_Filter)
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
            $product_filter = Product_Filter::findOrFail($id);
            $source = $product_filter->source;

            $productData = $product_filter->toArray();
            unset($productData['id'], $productData['user_id'], $productData['source']);

            if ($source === 'staging') {
                StagingProduct::create($productData);
            } else {
                New_product::create($productData);
            }

            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "Berhasil menghapus list product filter", null);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }


    public function listFilterScans()
    {
        $userId = auth()->id();
        $product_filtersByUser = Product_Filter::where('user_id', $userId)->get();

        $totalNewPriceWithCategory = $product_filtersByUser->whereNotNull('new_category_product')->sum('old_price_product');
        $totalOldPriceWithoutCategory = $product_filtersByUser->whereNull('new_category_product')->sum('old_price_product');

        $totalNewPrice = $totalNewPriceWithCategory + $totalOldPriceWithoutCategory;

        $category = null;
        $color = null;
        $fixed_price = null;

        if ($totalNewPrice > 119999) {
            $category = Category::all();
        } else {
            foreach ($product_filtersByUser as $product_filter) {
                $colorTag = ColorTag2::where('min_price_color', '<=', $totalNewPrice)
                    ->where('max_price_color', '>=', $totalNewPrice)
                    ->select('fixed_price_color', 'name_color')
                    ->first();

                if ($colorTag) {
                    $product_filter->new_tag_product = $colorTag->name_color;
                    $color = $colorTag->name_color;
                    $fixed_price = $colorTag->fixed_price_color;
                }
            }
        }

        $product_filters = Product_Filter::latest()->paginate(50);

        return new ResponseResource(true, "list product filter", [
            'total_new_price' => $totalNewPrice,
            'color' => $color,
            'fixed_price' => $fixed_price,
            'category' => $category,
            'data' => $product_filters
        ]);
    }



    public function destroyFilterScan($id)
    {
        DB::beginTransaction();
        try {
            $product_filter = Product_Filter::findOrFail($id);
            ProductInput::create($product_filter->toArray());
            $product_filter->delete();
            DB::commit();
            return new ResponseResource(true, "berhasil menghapus list product bundle", $product_filter);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
