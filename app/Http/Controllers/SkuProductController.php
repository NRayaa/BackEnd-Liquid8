<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\SkuDocument;
use App\Models\SkuProduct;
use Illuminate\Http\Request;

class SkuProductController extends Controller
{
    public function index(Request $request)
    {
        $codeDocument = $request->input('code_document');
        $keyword = $request->input('q');

        if (!$codeDocument) {
            return (new ResponseResource(false, "Parameter 'code_document' wajib diisi.", null))
                ->response()
                ->setStatusCode(400);
        }

        $query = SkuProduct::where('code_document', $codeDocument);

        if ($keyword) {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('barcode_product', 'LIKE', '%' . $keyword . '%')
                    ->orWhere('name_product', 'LIKE', '%' . $keyword . '%');
            });
        }

        $products = $query->latest()->paginate(50);

        $document = SkuDocument::where('code_document', $codeDocument)->first();

        if (!$document) {
            return (new ResponseResource(false, "Dokumen tidak ditemukan.", null))
                ->response()
                ->setStatusCode(404);
        }

        foreach ($products as $product) {
            $product->custom_barcode = $document->custom_barcode ?? null;
        }

        return new ResponseResource(true, "List Produk Final (SKU)", [
            'document_info' => [
                'code_document' => $document->code_document,
                'base_document' => $document->base_document,
                'status' => $document->status_document,
                'total_items' => $document->total_column_in_document,
                'custom_barcode' => $document->custom_barcode,
                'submitted_at' => $document->updated_at,
            ],
            'products' => $products
        ]);
    }

    public function show($id)
    {
        $product = SkuProduct::find($id);

        if (!$product) {
            return (new ResponseResource(false, "Produk tidak ditemukan", null))
                ->response()
                ->setStatusCode(404);
        }

        return new ResponseResource(true, "Detail Produk SKU", $product);
    }
}
