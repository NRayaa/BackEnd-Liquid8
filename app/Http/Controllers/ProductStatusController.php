<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ProductStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductStatusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index2()
    {
        $productStatuses = ProductStatus::paginate(33);
        return new ResponseResource(true, "list status", $productStatuses);
    }

    public function index()
    {
        $productStatuses = ProductStatus::get();
        return new ResponseResource(true, "list status", $productStatuses);

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
        $validator = Validator::make($request->all(), [
            'status_name' => 'required|min:3'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['status_slug'] = Str::slug($request['status_name']);
            $productStatus = ProductStatus::create($request->all());
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $productStatus);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductStatus $productStatus)
    {
        $resource = new ResponseResource(true, "Data status produk", $productStatus);
        return $resource->response();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductStatus $productStatus)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductStatus $productStatus)
    {
        $validator = Validator::make($request->all(), [
            'status_name' => 'required|min:3'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            $request['status_slug'] = Str::slug($request['status_name']);
            $productStatus->update($request->all());
            $resource = new ResponseResource(true, "Data berhasil disimpan!", $productStatus);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di simpan!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductStatus $productStatus)
    {
        try {
            $productStatus->delete();
            $resource = new ResponseResource(true, "Data berhasil di hapus!", $productStatus);
            return $resource->response();
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "Data gagal di hapus!", [$e->getMessage()]);
            return $resource->response()->setStatusCode(422);
        }
    }
}
