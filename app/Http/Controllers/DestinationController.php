<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Destination;
use App\Services\Olsera\OlseraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DestinationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $destinations = Destination::query();
        if ($query) {
            $destinations = $destinations->latest()->where('shop_name', 'LIKE', '%' . $query . '%');
        }
        $destinations = $destinations->paginate(50);
        return new ResponseResource(true, "list Destinasi Toko", $destinations);
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
        $request->validate([
            'shop_name' => 'required|string|max:255|unique:destinations,shop_name',
            'phone_number' => 'required|string|max:15',
            'alamat' => 'required|string',
        ]);

        $destination = Destination::create($request->all());
        return new ResponseResource(true, "Destination added successfully", $destination);
    }

    /**
     * Display the specified resource.
     */
    public function show(Destination $destination)
    {
        return new ResponseResource(true, "Detail Destination", $destination);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Destination $destination)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Destination $destination)
    {
        $request->validate([
            'shop_name' => 'required|string|max:255|unique:destinations,shop_name,' . $destination->id,
            'phone_number' => 'required|string|max:15',
            'alamat' => 'required|string',
        ]);

        $destination->update($request->all());
        return new ResponseResource(true, "updated destionation successfully", $destination);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Destination $destination)
    {
        $destination->delete();
        return new ResponseResource(true, "Destination deleted successfully", $destination);
    }

    public function syncOlseraTokens(Request $request)
    {
        try {
            $destinations = Destination::where('is_olsera_integrated', true)->get();
            $report = [];

            foreach ($destinations as $destination) {
                try {
                    $olseraService = new OlseraService($destination);

                    $olseraService->requestNewToken();

                    $report[] = [
                        'toko' => $destination->shop_name,
                        'status' => 'Sukses',
                        'message' => 'Token berhasil diperbarui di database'
                    ];
                } catch (\Exception $e) {
                    $report[] = [
                        'toko' => $destination->shop_name,
                        'status' => 'Gagal',
                        'message' => 'Alasan: ' . $e->getMessage()
                    ];
                }
            }

            return new ResponseResource(true, "Proses Sinkronisasi Token Olsera Selesai", $report);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
            ], 500);
        }
    }
}
