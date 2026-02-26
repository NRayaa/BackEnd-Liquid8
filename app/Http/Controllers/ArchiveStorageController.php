<?php

namespace App\Http\Controllers;

use App\Exports\ArchiveStorageExport;
use Carbon\Carbon;
use App\Models\New_product;
use Illuminate\Http\Request;
use App\Models\ArchiveStorage;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\ResponseResource;

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
        $dataStorageReport = $storageReport->storageReportForArchive()->getData(true);
        $archive = [];

        foreach ($dataStorageReport['data']['resource']['chart']['category'] as $data) {
            $archive[] = ArchiveStorage::create([
                'category_product' => $data['category_product'],
                'total_category' => $data['total_category'],
                'value_product' => (float) $data['total_price_category'],
                'month' => $dataStorageReport['data']['resource']['month']['current_month']['month'],
                'year' => $dataStorageReport['data']['resource']['month']['current_month']['year'],
                'type' => 'type1',
            ]);
        }
        foreach ($dataStorageReport['data']['resource']['chart_staging']['category'] as $data) {
            $archive[] = ArchiveStorage::create([
                'category_product' => $data['category_product'],
                'total_category' => $data['total_category'],
                'value_product' => (float) $data['total_price_category'],
                'month' => $dataStorageReport['data']['resource']['month']['current_month']['month'],
                'year' => $dataStorageReport['data']['resource']['month']['current_month']['year'],
                'type' => 'type2',
            ]);
        }

        foreach ($dataStorageReport['data']['resource']['tag_products'] as $data) {
            ArchiveStorage::create([
                'category_product' => null,
                'total_category' => 0,
                'value_product' => (float) $data['total_price_tag_product'],
                'total_color' => $data['total_tag_product'],
                'color' => $data['tag_product'],
                'month' => $dataStorageReport['data']['resource']['month']['current_month']['month'],
                'year' => $dataStorageReport['data']['resource']['month']['current_month']['year'],
                'type' => 'color',
            ]);
        }

        return new ResponseResource(true, "Berhasil melakukan archive!", $archive);
    }

    public function store2(Request $request, $month = null, $year = null)
    {
        $month = $month ?? $request->query('month', Carbon::now()->format('m'));
        $year = $year ?? $request->query('year', Carbon::now()->format('Y'));

        $monthName = Carbon::createFromFormat('m', $month)->translatedFormat('F');

        $storageReport = new DashboardController;
        $dataStorageReport = $storageReport->storageReport2($month, $year)->getData(true);

        // foreach ($dataStorageReport['data']['resource']['chart']['category'] as $data) {
        //     ArchiveStorage::create([
        //         'category_product' => $data['category_product'],
        //         'total_category' => $data['total_category'],
        //         'value_product' => (float) $data['total_price_category'],
        //         'month' => $monthName,
        //         'year' => $year,
        //         'type' => 'type1',
        //     ]);
        // }
        // foreach ($dataStorageReport['data']['resource']['chart_staging']['category'] as $data) {
        //     ArchiveStorage::create([
        //         'category_product' => $data['category_product'],
        //         'total_category' => $data['total_category'],
        //         'value_product' => (float) $data['total_price_category'],
        //         'month' => $monthName,
        //         'year' => $year,
        //         'type' => 'type2',
        //     ]);
        // }
        
        foreach ($dataStorageReport['data']['resource']['tag_products'] as $data) {
            ArchiveStorage::create([
                'category_product' => null,
                'total_category' => 0,
                'value_product' => (float) $data['total_price_tag_product'],
                'total_color' => $data['total_tag_product'],
                'color' => $data['tag_product'],
                'month' => $monthName,
                'year' => $year,
                'type' => 'color',
            ]);
        }

        return new ResponseResource(true, "Berhasil melakukan archive!", []);
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


    public function exports(Request $request)
    {
        $year = $request->query('year', Carbon::now()->format('Y'));
        $month = $request->query('month');
        
        if ($month) {
            $monthName = Carbon::create($year, $month)->format('M');
            $fileName = "storage-report-{$monthName}-{$year}.xlsx";
        } else {
            $fileName = "storage-report-{$year}.xlsx";
        }

        $publicPath = 'reports';

        Excel::store(
            new ArchiveStorageExport($year, $month),
            $publicPath . '/' . $fileName,
            'public'
        );

        $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

        $url = asset('storage/' . $publicPath . '/' . $fileName);

        return response()->json([
            'success' => true,
            'url' => $url,
            'filename' => $fileName
        ]);
    }


   
}
