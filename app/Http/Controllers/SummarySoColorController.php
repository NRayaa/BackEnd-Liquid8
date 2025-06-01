<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\SoColor;
use Illuminate\Http\Request;
use App\Models\SummarySoColor;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\New_product;
use Illuminate\Support\Facades\Validator;

class SummarySoColorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $q = $request->input('q', '');

        $summarySoColors = SummarySoColor::with('soColors')
            ->when($q, function ($query) use ($q) {
                $query->where('start_date', 'like', '%' . $q . '%');
            })
            ->paginate(10);

        $status = SummarySoColor::where('type', 'process')->whereNull('end_date')->exists() ? 'process' : 'done';

        $summarySoColors->getCollection()->transform(function ($item) use ($status) {
            $item->status = $status;
            return $item;
        });

        $summarySoColors->appends([
            'q' => $q,
            'status' => $status,
        ]);

        return new ResponseResource(true, 'Summary SO Colors retrieved successfully', $summarySoColors);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(SummarySoColor $summarySoColor)
    {
        if (!$summarySoColor) {
            return new ResponseResource(false, 'Summary SO Color not found', null, 404);
        }
        $summarySoColor->load('soColors');
        return new ResponseResource(true, 'Summary SO Color retrieved successfully', $summarySoColor);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SummarySoColor $summarySoColor)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SummarySoColor $summarySoColor)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SummarySoColor $summarySoColor)
    {
        //
    }

    public function startSoColor(Request $request)
    {
        DB::beginTransaction();
        try {

            $checkSummary = SummarySoColor::where('type', 'process')->where('end_date', null)->first();
            if ($checkSummary) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "SO process already started",
                    null
                ))->response()->setStatusCode(422);
            }
            $newPeriod = SummarySoColor::create([
                'type' => 'process',
                'start_date' => Carbon::now('Asia/Jakarta'),
                'end_date' => null,
            ]);

            DB::commit();
            return new ResponseResource(true, "SO process started successfully", $newPeriod);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function stopSo(Request $request)
    {
        DB::beginTransaction();
        try {
            $activePeriod = SummarySoColor::where('type', 'process')->where('end_date', null)->first();
            if (!$activePeriod) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "No active SO period found",
                    null
                ))->response()->setStatusCode(422);
            }

            $activePeriod->update([
                'end_date' => Carbon::now('Asia/Jakarta'),
                'type' => 'done',
            ]);

            DB::commit();
            return new ResponseResource(true, "SO process stopped successfully", $activePeriod);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function soColor(Request $request)
    {
        set_time_limit(1200);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                // Validasi array colors
                'colors' => 'required|array|min:1',
                'colors.*.name_color'        => 'nullable|string',
                'colors.*.total_all'             => 'nullable|integer',
                'colors.*.product_damaged'   => 'nullable|integer',
                'colors.*.product_abnormal'  => 'nullable|integer',
                'colors.*.lost'              => 'nullable|integer',
                'colors.*.addition'          => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Cek apakah ada periode SO yang aktif
            $activePeriod = SummarySoColor::where('type', 'process')->first();
            if (!$activePeriod) {
                DB::rollBack();
                return (new ResponseResource(
                    false,
                    "No active SO period found",
                    null
                ))->response()->setStatusCode(422);
            }

            foreach ($request->colors as $color) {
                SoColor::create([
                    'summary_so_color_id' => $activePeriod->id,
                    'color' => $color['name_color'] ?? null,
                    'total_color' => $color['total_all'] ?? 0,
                    'product_damaged' => $color['product_damaged'] ?? 0,
                    'product_abnormal' => $color['product_abnormal'] ?? 0,
                    'product_lost' => $color['lost'] ?? 0,
                    'product_addition' => $color['addition'] ?? 0,
                ]);

                $totalLolos = $color['total_all'] - $color['product_damaged'] - $color['product_abnormal'];

                $productLolos = New_product::whereNull('is_so')
                    ->where('new_tag_product', $color['name_color'])
                    ->whereRaw("JSON_EXTRACT(new_quality, '$.\"lolos\"') = 'lolos'")
                    ->limit($totalLolos)
                    ->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                    ]);

                $productDamaged = New_product::whereNull('is_so')
                    ->whereNotNull('new_tag_product')
                    ->whereNotNull('new_quality->damaged')
                    ->limit($color['product_damaged'])
                    ->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                    ]);

                $productAbnormal = New_product::whereNull('is_so')
                    ->whereNotNull('new_tag_product')
                    ->whereNotNull('new_quality->abnormal')
                    ->limit($color['product_abnormal'])
                    ->update([
                        'is_so' => 'check',
                        'user_so' => auth()->user()->id,
                    ]);
            }

            DB::commit();
            return new ResponseResource(true, "SO colors added successfully", null);
        } catch (Exception $e) {
            DB::rollBack();
            return (new ResponseResource(
                false,
                "An error occurred: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }
}
