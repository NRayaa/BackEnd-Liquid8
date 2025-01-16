<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\New_product;
use App\Models\ProductScan;
use Illuminate\Http\Request;
use App\Models\FilterStaging;
use App\Models\Color_tag;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\ProductInput;
use Illuminate\Support\Facades\Validator;

class ProductScanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
    
        $productScanQuery = ProductScan::with('user')->latest();
    
        if ($query) {
            $productScanQuery->where('product_name', 'LIKE', '%' . $query . '%');
        }
    
        $productScans = $productScanQuery->paginate(20);
    
        return new ResponseResource(true, "list products scan", $productScans);
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
        // Validasi Input
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string',
            'product_price' => 'required|numeric',
            'image' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            
            $productScan = ProductScan::create([
                'user_id' => auth()->id(),
                'product_name' => $request['product_name'],
                'product_price' => $request['product_price'],
                'image' => $request['image']
            ]);

            DB::commit(); 

            return new ResponseResource(true, "berhasil menambah data scan", $productScan);
        } catch (\Exception $e) {
            DB::rollback(); // Rollback jika terjadi error
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductScan $productScan)
    {
        return new ResponseResource(true, "detail data scan", $productScan);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductScan $productScan)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductScan $productScan)
    {
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string',
            'product_price' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $productScan->update([
                'product_name' => $request->input('product_name'),
                'product_price' => $request->input('product_price')
            ]);
            DB::commit();
            return new ResponseResource(true, "berhasil di update", $productScan);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductScan $productScan)
    {
        try {
            if ($productScan) {
                $productScan->delete();
                return new ResponseResource(true, "berhasil di hapus", null);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function product_scan_search(Request $request)
    {
        try {
            $product = ProductScan::where('id', $request->input('id'))->latest()->first();

            if ($product) {
                $generate = newBarcodeScan();
                $response = ['product' => $product];
                $response['product']['new_barcode_product'] = $generate;
                $response['product']['old_barcode_product'] = $generate;

                if ($product->product_price <= 99999) {
                    $response['color_tags'] = Color_tag::where('min_price_color', '<=', $product->product_price)
                        ->where('max_price_color', '>=', $product->product_price)
                        ->first();
                }

                return new ResponseResource(true, "Data ditemukan", $response);
            } else {
                return (new ResponseResource(false, "Produk tidak ada", null))
                    ->response()->setStatusCode(404);
            }
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Terjadi kesalahan pada sistem", $e->getMessage()))
                ->response()->setStatusCode(500);
        }
    }


    public function move_to_staging(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // 'code_document' => 'required',
            'old_barcode_product' => 'nullable',
            'new_barcode_product' => 'required|unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            // 'new_date_in_product' => 'required|date',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',

        ], [
            'new_barcode_product.unique' => 'barcode sudah ada',
            'old_barcode_product.exists' => 'barcode tidak ada'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $status = $request->input('condition');
        $description = $request->input('deskripsi', '');

        $qualityData = $this->prepareQualityData($status, $description);

        $inputData = $this->prepareInputDataScan($request, $status, $qualityData);


        DB::beginTransaction();
        try {

            $tables = [
                New_product::class,
                StagingProduct::class,
                StagingApprove::class,
                FilterStaging::class,
            ];

            $newBarcodeExists = false;

            foreach ($tables as $table) {
                if ($table::where('new_barcode_product', $inputData['new_barcode_product'])->exists()) {
                    $newBarcodeExists = true;
                }
            }

            if ($newBarcodeExists) {
                return new ResponseResource(false, "The new barcode already exists", $inputData);
            }

            // $this->deleteProductScan($request->input('new_name_product'));
            if ($inputData['new_tag_product'] !== null) {
                $newProduct = New_product::create($inputData);
            } else {
                $newProduct = ProductInput::create($inputData);
            }
            DB::commit();

            return new ResponseResource(true, "Produk Berhasil ditambah ke product input", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function to_product_input(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // 'code_document' => 'required',
            'old_barcode_product' => 'nullable',
            'new_barcode_product' => 'required|unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            // 'new_date_in_product' => 'required|date',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'required|in:lolos,damaged,abnormal',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',

        ], [
            'new_barcode_product.unique' => 'barcode sudah ada',
            'old_barcode_product.exists' => 'barcode tidak ada'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $status = $request->input('condition');
        $description = $request->input('deskripsi', '');

        $qualityData = $this->prepareQualityData($status, $description);

        $inputData = $this->prepareInputDataScan($request, $status, $qualityData);


        DB::beginTransaction();
        try {

            $tables = [
                New_product::class,
                StagingProduct::class,
                StagingApprove::class,
                FilterStaging::class,
            ];

            $newBarcodeExists = false;

            foreach ($tables as $table) {
                if ($table::where('new_barcode_product', $inputData['new_barcode_product'])->exists()) {
                    $newBarcodeExists = true;
                }
            }

            if ($newBarcodeExists) {
                return new ResponseResource(false, "The new barcode already exists", $inputData);
            }

            // $this->deleteProductScan($request->input('new_name_product'));
            if ($inputData['new_tag_product'] !== null) {
                $newProduct = New_product::create($inputData);
            } else {
                $newProduct = ProductInput::create($inputData);
            }
            DB::commit();

            return new ResponseResource(true, "Produk Berhasil ditambah ke product input", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function prepareQualityData($status, $description)
    {
        return [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null
        ];
    }

    private function prepareInputDataScan($request, $status, $qualityData)
    {

        $inputData = $request->only([
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_status_product',
            'new_category_product',
            'new_tag_product',
            'condition',
            'deskripsi',

        ]);

        $inputData['old_barcode_product'] = $inputData['new_barcode_product'];
        $inputData['code_document'] = null;
        $inputData['new_status_product'] = "display";
        $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
        $inputData['new_quality'] = json_encode($qualityData);
        $inputData['new_discount'] = 0;
        $inputData['display_price'] = $inputData['new_price_product'];

        if ($status !== 'lolos') {
            $inputData['new_category_product'] = null;
            $inputData['new_price_product'] = null;
        }

        if ($inputData['new_price_product'] == null) {
            $inputData['display_price'] = 0;
        }

        return $inputData;
    }

    private function deleteProductScan($product_name)
    {
        $product = DB::table('product_scans')->where('product_name', $product_name)->latest()->first();

        if ($product) {
            $affectedRows = DB::table('product_scans')->where('id', $product->id)->delete();
            return $affectedRows > 0;
        } else {
            return new ResponseResource(false, 'data tidak ditemukan', null);
        }
    }
}
