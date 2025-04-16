<?php

namespace App\Http\Controllers;

use App\Http\Resources\FormatBarcodeResource;
use App\Http\Resources\ResponseResource;
use App\Models\FormatBarcode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FormatBarcodeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('q');
        $page = $request->input('page', 1);
        try {
            $formatBarcodes = FormatBarcode::latest();

            if ($search) {
                $formatBarcodes->where(function ($query) use ($search) {
                    $query->where('format', 'LIKE', '%' . $search . '%');
                });
                $page = 1;
            }
            $paginatedFormatBarcodes = $formatBarcodes->paginate(33, ['*'], 'page', $page);
            return new ResponseResource(true, "list format barcodes", $paginatedFormatBarcodes);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "data tidak ada", $e->getMessage()))->response()->setStatusCode(500);
        }
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
        $userId = auth()->id();
        $validator = Validator::make($request->all(), [
            'format' => 'required|string|unique:format_barcodes,format'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $insert = FormatBarcode::create([
                'format' => $request->input('format'),
                'total_user' => 0,
                'total_scan' => 0,
                'user_id' => $userId
            ]);

            return new ResponseResource(true, "berhasil ditambahkan", $insert);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan format barcode',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(FormatBarcode $formatBarcode)
    {
        if (!$formatBarcode || !$formatBarcode->exists) {
            return (new ResponseResource(false, "Format barcode tidak ditemukan", []))
                ->response()
                ->setStatusCode(404);
        }

        // $formatBarcode->load(['users.user_scans']);
        $formatBarcode->load(['user_scans.user']);

        return new ResponseResource(true, "detail format barcode", new FormatBarcodeResource($formatBarcode));
        // return new ResponseResource(true, "detail format barcode", $formatBarcode);

    }
 
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FormatBarcode $formatBarcode)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FormatBarcode $formatBarcode)
    {
        $userId = auth()->id();
        $validator = Validator::make($request->all(), [
            'format' => 'required|string|unique:format_barcodes,format,' . $formatBarcode->id,
            'total_user' => 'nullable|integer|min:0',
            'total_scan' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $formatBarcode->update([
                'format' => $request->input('format'),
                'total_user' => $formatBarcode->total_user ?? null,
                'total_scan' => $formatBarcode->total_scan ?? null,
                'user_id' => $userId
            ]);

            return new ResponseResource(true, "Berhasil mengedit format barcode", $formatBarcode);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengedit format barcode',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FormatBarcode $formatBarcode)
    {
        try {
            $formatBarcode->delete();

            return new ResponseResource(true, "Format barcode berhasil dihapus", []);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus format barcode',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function formatsUsers(){
        $formatBarcodes = FormatBarcode::latest()->get();
        $users = User::latest()->get();
        return new ResponseResource(true, "list format barcode dan user", [
            "format_barcode" => $formatBarcodes,
            "users" => $users
        ]);
    }
}
