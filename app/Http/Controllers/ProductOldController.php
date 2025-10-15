<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Document;
use App\Models\Color_tag;
use App\Models\New_product;
use App\Models\Product_old;
use Illuminate\Http\Request;
use App\Models\Product_Bundle;
use App\Models\StagingProduct;
use App\Http\Resources\ResponseResource;

class ProductOldController extends Controller
{

    public function searchByBarcode(Request $request)
    {
        $codeDocument = $request->input('code_document');
        $oldBarcode = $request->input('old_barcode_product');

        if (!$codeDocument) {
            return new ResponseResource(false, "Code document tidak boleh kosong.", null);
        }

        if (!$oldBarcode) {
            return new ResponseResource(false, "Barcode tidak boleh kosong.", null);
        }

        // $checkBarcode = New_product::where('code_document', $codeDocument)
        //     ->where('old_barcode_product', $oldBarcode)
        //     ->exists();

        // if ($checkBarcode) {
        //     return new ResponseResource(false, "barcode dari file sudah ada di display.", []);
        // }

        $product = Product_old::where('code_document', $codeDocument)
            ->where('old_barcode_product', $oldBarcode)
            ->first();

        if (!$product) {
            return new ResponseResource(false, "Produk tidak ditemukan.", []);
        }

        // $newBarcode = $this->generateUniqueBarcode();
        $response = ['product' => $product];

        if ($product->old_price_product <= 99999) {
            $response['color_tags'] = Color_tag::where('min_price_color', '<=', $product->old_price_product)
                ->where('max_price_color', '>=', $product->old_price_product)
                ->get();
        }


        return new ResponseResource(true, "Produk ditemukan.", $response);
    }


    private function generateUniqueBarcode()
    {
        $prefix = 'LQD';
        do {
            $randomNumber = str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $barcode = $prefix . $randomNumber;
        } while (New_product::where('new_barcode_product', $barcode)->exists());

        return $barcode;
    }


    public function searchByDocument(Request $request)
    {
        $query = $request->input('q');
        $search = $request->input('search');

        $code_documents = Product_old::where('code_document', $search)
            ->where(function ($subQuery) use ($query) {
                $subQuery->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_name_product', 'LIKE', '%' . $query . '%');
            })
            ->paginate(50);

        $document = Document::where('code_document', $search)->first();

        if ($document) {
            foreach ($code_documents as $code_document) {
                $code_document->custom_barcode = $document->custom_barcode ?? null;
            }

            return new ResponseResource(true, "Data Document products", [
                'document_name' => $document->base_document ?? 'N/A',
                'status' => $document->status_document ?? 'N/A',
                'total_columns' => $document->total_column_in_document ?? 0,
                'custom_barcode' => $document->custom_barcode ?? null,
                'code_document' => $document->code_document ?? 'N/A',
                'data' => $code_documents ?? null,
            ]);
        } else {
            // Dokumen tidak ditemukan
            return (new ResponseResource(false, "code document tidak ada", null))
                ->response()
                ->setStatusCode(404);
        }
    }




    public function index()
    {
        $product_olds = Product_old::latest()->paginate(50);

        return new ResponseResource(true, "list all product_old", $product_olds);
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
    public function show(Product_old $product_old)
    {
        return new ResponseResource(true, "data product_old", $product_old);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product_old $product_old)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product_old $product_old)
    {
        $product_old->delete();
        return new ResponseResource(true, "berhasil di hapus", $product_old);
    }
    public function deleteAll()
    {
        try {
            Product_old::truncate();

            return new ResponseResource(true, "Semua data berhasil dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Terjadi kesalahan saat menghapus data", null);
        }
    }

    public function getProductLolos(Request $request, $code_document)
    {
        $search = $request->input('q');

        $inventory = New_product::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $stagings = StagingProduct::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $bundles = Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->lolos', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $sales = Sale::where('code_document_sale', $code_document)
            ->select(
                'code_document_sale AS code_document',
                'product_name_sale AS new_name_product',
                'product_barcode_sale AS new_barcode_product',
                'product_qty_sale AS new_quantity_product',
                'product_old_price_sale AS old_price_product',
                'product_barcode_sale AS old_barcode_product'
            );

        if ($search) {
            $inventory->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $stagings->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $bundles->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $sales->where(function ($query) use ($search) {
                $query->where('product_barcode_sale', 'LIKE', '%' . $search . '%')
                    ->orWhere('product_name_sale', 'LIKE', '%' . $search . '%');
            });
        }

        // Combine all queries using Union
        $combined = $inventory->union($stagings)->union($bundles)->union($sales)->paginate(50);

        // Return the response with the paginated result
        return new ResponseResource(true, "list lolos", $combined);
    }

    public function getProductDamaged(Request $request, $code_document)
    {
        $search = $request->input('q');

        $inventory = New_product::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $stagings = StagingProduct::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $bundles = Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->damaged', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $sales = Sale::where('code_document_sale', $code_document)
            ->select(
                'code_document_sale AS code_document',
                'product_name_sale AS new_name_product',
                'product_barcode_sale AS new_barcode_product',
                'product_qty_sale AS new_quantity_product',
                'product_old_price_sale AS old_price_product',
                'product_barcode_sale AS old_barcode_product'
            );

        if ($search) {
            $inventory->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $stagings->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $bundles->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $sales->where(function ($query) use ($search) {
                $query->where('product_barcode_sale', 'LIKE', '%' . $search . '%')
                    ->orWhere('product_name_sale', 'LIKE', '%' . $search . '%');
            });
        }

        // Combine all queries using Union
        $combined = $inventory->union($stagings)->union($bundles)->union($sales)->paginate(50);

        return new ResponseResource(true, "list damaged", $combined);
    }
    public function getProductAbnormal(Request $request, $code_document)
    {
        $search = $request->input('q');

        $inventory = New_product::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $stagings = StagingProduct::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $bundles = Product_Bundle::where('code_document', $code_document)
            ->where('new_quality->abnormal', '!=', null)
            ->select('code_document', 'new_name_product', 'old_barcode_product', 'new_barcode_product', 'new_quantity_product', 'old_price_product');

        $sales = Sale::where('code_document_sale', $code_document)
            ->select(
                'code_document_sale AS code_document',
                'product_name_sale AS new_name_product',
                'product_barcode_sale AS new_barcode_product',
                'product_qty_sale AS new_quantity_product',
                'product_old_price_sale AS old_price_product',
                'product_barcode_sale AS old_barcode_product'
            );

        if ($search) {
            $inventory->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $stagings->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $bundles->where(function ($query) use ($search) {
                $query->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
            });

            $sales->where(function ($query) use ($search) {
                $query->where('product_barcode_sale', 'LIKE', '%' . $search . '%')
                    ->orWhere('product_name_sale', 'LIKE', '%' . $search . '%');
            });
        }

        // Combine all queries using Union
        $combined = $inventory->union($stagings)->union($bundles)->union($sales)->paginate(50);

        return new ResponseResource(true, "list abnormal", $combined);
    }

    public function discrepancy(Request $request, $code_document)
    {
        $search = $request->input('q');

        $products = Product_old::where('code_document', $code_document);

        if ($search) {
            $products->where(function ($query) use ($search) {
                $query->where('old_barcode_product', 'LIKE', '%' . $search . '%')
                    ->orWhere('old_name_product', 'LIKE', '%' . $search . '%');
            });
        }

        $productsPaginated = $products->paginate(50);

        return new ResponseResource(true, "list discrepancy", $productsPaginated);
    }
}
