<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\ArchiveStorage;
use App\Models\New_product;
use Illuminate\Http\Request;

class ArchiveStorageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store()
    {
        $storageReport = new DashboardController;
        $dataStorageReport = $storageReport->storageReport()->getData(true);
        $archive = [];
        
        // Ubah ini: akses 'category' di dalam 'chart'
        foreach ($dataStorageReport['data']['resource']['chart']['category'] as $data) {
            $archive[] = ArchiveStorage::create([
                'category_product' => $data['category_product'],
                'total_category' => $data['total_category'],
                'value_product' => (float) $data['total_price_category'],
                'month' => $dataStorageReport['data']['resource']['month']['current_month']['month'],
                'year' => $dataStorageReport['data']['resource']['month']['current_month']['year'],
            ]);
        }
        
        return new ResponseResource(true, "Berhasil melakukan archive!", $archive);
    }

    /**
     * Display the specified resource.
     */
    public function show(ArchiveStorage $archiveStorage)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ArchiveStorage $archiveStorage)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ArchiveStorage $archiveStorage)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ArchiveStorage $archiveStorage)
    {
        //
    }

    //function untuk check json new_quality lolos, lolos,damaged,abnormal
    public function checkQuality(Request $request)
    {
        $newProduct = New_product::where('new_quality->lolos', '!=', null)->get();

        return $newProduct;
    }
}
